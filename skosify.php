<?php
/*YamlDoc:
title: skosify.php - génération d'une version Skos des thèmes Ecosphères
name: skosify.php
classes:
doc: |
  Lit le fichier des thèmes en Yaml en paramètre et fabrique le fichier Skos correspondant.
  Peut afficher en Turtle ou dans les autres formats proposés par EasyRdf ainsi que Yaml-LD.
  Le fichier yaml des thèmes est dans un schéma adhoc défini pour simplifier la gestion.
journal: |
  7/7/2022:
    - généralisation 
  5/7/2022:
    - création
*/
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

if ($argc == 1) {
?>
usage: php $argv[0] [-short] {yamlFile} [{fmt}]
L'option '-short' limite le traitement au premier thème et à ses sous-thèmes.
Sans format affiche le turtle par défaut.
Le format 'dump' permet d'afficher les données dans la structure interne.
La définition d'un autre format conduit à utiliser EasyRdf pour lequel les formats suivants sont proposés:
<?php
  foreach (\EasyRdf\Format::getFormats() as $format)
    echo " - $format\n";
?>
De plus, sont proposés:
 - yamlld
 - cjsonld pour JSON-LD compressé en utilisant les prefixes comme contexte
 - cyamlld idem en Yaml
<?php
  die();
}

array_shift($argv);
$short = false;
if ($argv[0] == '-short') {
  $short = true;
  array_shift($argv);
}


function array_is_dict(array $array): bool {
  return count(array_diff_key($array, array_keys(array_keys($array))));
}

if (!function_exists('array_is_list')) {
  function array_is_list($list): bool { return is_array($list) && !array_is_dict($list); }
}

function beautifulYaml(string $yamlText): string {
  //return $yamlText;
  return preg_replace('!-\n +!', '- ', $yamlText);
}

class Label { // Une étiquette potentiellement dans différentes langues
  protected array $strings; // [{lang}=> string]
  
  static function is($val): bool {
    if (!is_array($val))
      return false;
    else {
      foreach ($val as $lang => $string) {
        if (($lang <> 'x') && (strlen($lang)<>2))
          return false;
        if (!is_string($string))
          return false;
      }
    }
    return true;
  }
  
  function __construct(array $strings) { $this->strings = $strings; }
  
  function asArray(): array { return $this->strings; }
  
  function asTurtle(): string {
    $ttl = '';
    foreach ($this->strings as $lang => $string)
      $ttl .= "\"$string\"".($lang<>'x' ? "@$lang" : '').', ';
    return substr($ttl, 0, -2);
  }
};

{/*YamlDoc: classes
title: class RdfResource - déf. d'une ressource RDF et stockage des prefixes
name: RdfResource
methods:
doc: |
  La classe RdfResource définit la structuration d'un ressource RDF dans le champ $prop.
  Elle définit aussi 1 variable statique stockant les prefixes utilisés
  Dans le fichier Yaml en entrée, les concepts peuvent structurés hiérarchiquement,
  le chargement du fichier applatit la hiérarchie en stockant tous les concepts dans Concept::$all
  et en les remplacant dans la hiérarchie par l'URI du concept.
  Le chargement ajoute aussi à chaque concept:
   - le type RDF skos:Concept
   - la propriété skos:inScheme vers le Scheme défini dans Scheme si elle n'est pas définie
  RdfResource est assez générique et indépendant des types de ressources dont les spécificités sont
  déportées dans les différentes méthodes __construct()
*/}
abstract class RdfResource {
  const SchemasDefinitions = [
    "PredicatesObjects" => [
      "description" => "la partie prédicats-objets d'un triplet, le prédicat comme clé sous la forme d'un URI compacté et les objets associés comme valeurs.",
      "type" => "object",
      "additionalProperties" => false,
      "patternProperties" => [
        "^[a-z]+:[a-zA-Z]+$" => [
          '$ref' => "#/definitions/Objects",
        ],
      ],
    ],
    "Objects" => [
      "description" => "la partie objets associée à un sujet et un prédicat",
      "oneOf" => [
        [
          "description" => "une URI",
          '$ref' => "#/definitions/Uri",
        ],
        [
          "description" => "une liste d'URI",
          "type" => "array",
          "items" => [
            '$ref' => "#/definitions/Uri",
          ],
        ],
        [
          "description" => "URI compacté",
          '$ref' => "#/definitions/CompactUri",
        ],
        [
          "description" => "liste d'URI compactés",
          "type" => "array",
          "items" => [
            '$ref' => "#/definitions/CompactUri",
          ],
        ],
        [
          "description" => "un libellé dans une ou plusieurs langues, x pour neutre",
          '$ref' => "#/definitions/Label",
        ],
        [
          "description" => "une liste de libellés, chacun en une ou plusieurs langues, x pour neutre",
          "type" => "array",
          "items" => [
            '$ref' => "#/definitions/Label",
          ],
        ],
        [
          "description" => "un ensemble de triplets, chaque sujet comme clé sous la forme d'un URI compacté et les prédicats-objets associés comme valeurs.",
          "type" => "object",
          "additionalProperties" => false,
          "patternProperties" => [
            "^[a-z]+:[-a-zA-Z0-9]+$" => [
              '$ref' => "#/definitions/PredicatesObjects",
            ],
          ],
        ],
      ],
    ],
  ];
  
  protected array $prop; // [{predicat} => [{object}]] où {object} ::= URI | compactURI | Label
  static array $prefix = [];
  
  /*YamlDoc: methods
  title: "function __construct(string $id, array $concept, ?string $parent=null)"
  doc: |
    Méthode générique et récursive de création appellée la méthode spécifique de création.
    $predicatesObjects est un array respectant le sous-schéma PredicatesObjects du schéma thespheres.schema.yaml
  */
  function __construct(string $id, array $predicatesObjects, ?string $parent=null) {
    foreach ($predicatesObjects as $predicate => $objects) {
      if (Label::is($objects)) { // objects ::= Label
        $this->prop[$predicate] = [new Label($objects)];
      }
      elseif (is_string($objects)) { // objects ::= Uri | CompactUri
        $this->prop[$predicate] = [$objects];
      }
      elseif (array_is_list($objects)) { // objects est une liste
        $this->prop[$predicate] = [];
        foreach ($objects as $item) {
          if (Label::is($item))
            $this->prop[$predicate][] = new Label($item); // objects ::= [Label]
          elseif (is_string($item))
            $this->prop[$predicate][] = $item; // objects ::= [Uri | CompactUri]
          else
            throw new Exception("cas non prévu");
        }
      }
      elseif (is_array($objects)) { // objects ::= setOfRdfResources
        $this->prop[$predicate] = [];
        foreach ($objects as $curi => $childPredicatesObjects) {
          new (get_class($this))($curi, $childPredicatesObjects, $id);
          $this->prop[$predicate][] = $curi;
        }
      }
      else
        throw new Exception("cas non prévu");
    }
  }
  
  private function asArray(): array { // Resource -> array
    $array = [];
    foreach ($this->prop as $predicate => $objects) {
      $array[$predicate] = [];
      foreach ($objects as $object)
        if (is_object($object))
          $array[$predicate][] = $object->asArray();
        else
          $array[$predicate][] = $object;
    }
    return $array;
  }
  
  // prend le contenu du fichier Yaml et construit la structure
  static function init(array $yaml, bool $short): void {
    self::$prefix = $yaml['prefix'];
    foreach ($yaml['skos:ConceptScheme'] as $id => $resource) {
      new Scheme($id, $resource);
    }
    foreach ($yaml['skos:Concept'] as $id => $resource) {
      new Concept($id, $resource);
      if ($short) break; // pour limiter à un seul thème de niveau 1 en tests
    }
  }
  
  static function allAsArray(): array { // retourne tous les éléments comme un array
    return [
      'prefix'=> self::$prefix,
      'schemes'=> Scheme::allResourcesAsArray(),
      'concepts'=> Concept::allResourcesAsArray(),
    ];
  }
  
  private static function allResourcesAsArray(): array { // retourne les ressources d'un type comme array
    $resources = [];
    foreach (get_called_class()::$all as $id => $resource) {
      $resources[$id] = $resource->asArray();
    }
    return $resources;
  }

  private function asTurtle(string $subject): string { // RdfResource -> Turtle
    //echo "asTurtle($subject, ",Yaml::dump($po),")\n";
    $ttl = "$subject\n";
    foreach ($this->prop as $predicate => $objects) {
      if (!$objects) continue;
      $ttl .= "  $predicate ";
      foreach ($objects as $io => $object) {
        if (is_string($object)) {
          if ((substr($object, 0, 7)=='http://') || (substr($object, 0, 8)=='https://')) // URI
            $ttl .= "<$object>";
          elseif (strpos($object, ':') !== false) // URI compacté
            $ttl .= $object;
          else {
            throw new Exception("Objet \"$object\" non interprété");
          }
        }
        elseif (is_object($object)) { // Etiquette
          $ttl .= $object->asTurtle();
        }
        else {
          throw new Exception("Objet non interprété");
        }
        if ($io <> (count($objects)-1))
          $ttl .= ", ";
        else
          $ttl .= ";\n";
      }
    }
    $ttl = substr($ttl, 0, -2);
    return "$ttl.\n";
  }

  private static function allResourcesAsTurtle(): string {
    $ttl = '';
    foreach (get_called_class()::$all as $uri => $resource)
      $ttl .= $resource->asTurtle($uri)."\n";
    return $ttl;
  }
  
  static function allAsTurtle(): string {
    $ttl = '';
    foreach (self::$prefix as $prefix => $uri)
      $ttl .= "@prefix $prefix: <$uri> .\n";
    $ttl .= "\n";
    $ttl .= Scheme::allResourcesAsTurtle();
    $ttl .= Concept::allResourcesAsTurtle();
    return $ttl;
  }
}

class Scheme extends RdfResource { // sous-classe des schemas avec des traitements spécifiques à la création 
  const Type = 'skos:ConceptScheme';

  static array $all = []; // [id => Scheme] - tous les schémas

  static function defaultSchemeId(): string { return array_keys(self::$all)[0]; }
  
  function __construct(string $id, array $resource, ?string $parent=null) {
    self::$all[$id] = $this;
    $this->prop = ['rdf:type'=> [self::Type]]; // rajout à chaque ressource de son type RDF
    $this->prop['skos:hasTopConcept'] = [];
    parent::__construct($id, $resource, $parent);
  }
};

class Concept extends RdfResource { // sous-classe des concepts avec des traitements spécifiques à la création 
  const Type = 'skos:Concept';

  static array $all = []; // [id => Concept] - tous les concepts

  function __construct(string $id, array $resource, ?string $parent=null) {
    self::$all[$id] = $this;
    $this->prop = ['rdf:type'=> [self::Type]]; // rajout à chaque ressource de son type RDF
    if (!isset($resource['skos:inScheme'])) { // si inScheme n'est pas défini
      $this->prop['skos:inScheme'] = [Scheme::defaultSchemeId()]; // je rajoute celui par défaut
      if (!$parent) { // si appel sur une racine <=> topConcept
        $this->prop['skos:topConceptOf'] = [Scheme::defaultSchemeId()];
        Scheme::$all[Scheme::defaultSchemeId()]->prop['skos:hasTopConcept'][] = $id;
      }
      else { // sinon appel sur un enfant alors
        $this->prop['skos:broader'] = [$parent];  // je renseigne son parent
      }
    }
    // j'initialise les champs valables pour tte ressource
    parent::__construct($id, $resource, $parent);
  }
};

RdfResource::init(Yaml::parseFile($argv[0]), $short);

// affichage du résultat
if (!isset($argv[1])) { // par défaut affichage de la sortie Turtle
  die(RdfResource::allAsTurtle());
}
elseif ($argv[1] == 'dump') { // dump brut de la structure construite pour débuggage 
  //print_r(['schemes'=> Scheme::$all, 'concepts'=> Concept::$all]);
  die(beautifulYaml(Yaml::dump(RdfResource::allAsArray(), 5, 2)));
}
else { // transformation par EasyRdf en jsonld, yamlld ou autre
  $graph = new \EasyRdf\Graph(Scheme::defaultSchemeId());
  $graph->parse(RdfResource::allAsTurtle(), 'turtle', Scheme::defaultSchemeId());
  switch ($ofmt = $argv[1]) {
    case 'jsonld': {
      $ser = $graph->serialise($ofmt);
      echo json_encode(json_decode($ser), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
      break;
    }
    case 'yamlld': {
      $ser = $graph->serialise('jsonld');
      echo beautifulYaml(Yaml::dump(json_decode($ser, true), 6, 2));
      break;
    }
    case 'cjsonld': {
      $ser = $graph->serialise('jsonld');
      $compacted = ML\JsonLD\JsonLD::compact($ser, json_encode(RdfResource::$prefix));
      echo json_encode($compacted, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
      break;
    }
    case 'cyamlld': {
      $ser = $graph->serialise('jsonld');
      $compacted = ML\JsonLD\JsonLD::compact($ser, json_encode(RdfResource::$prefix));
      echo beautifulYaml(Yaml::dump(json_decode(json_encode($compacted), true), 4, 2)),"\n";
      break;
    }
    default: {
      $ser = $graph->serialise($ofmt);
      echo is_string($ser) ? $ser : beautifulYaml(Yaml::dump($ser, 6, 2));
      break;
    }
  }
}
