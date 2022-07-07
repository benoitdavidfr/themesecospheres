<?php
/*YamlDoc:
title: skosify.php - génération d'une version Skos des thèmes Ecosphères
name: skosify.php
classes:
doc: |
  Lit le fichier des thèmes en Yaml en paramètre et fabrique le fichier Skos correspondant.
  Peut afficher en Turtle ou dans les autres formats proposés par EasyRdf ainsi que Yaml-LD

journal: |
  5/7/2022:
    - création
*/
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// Définit le fuseau horaire par défaut à utiliser.
//date_default_timezone_set('Europe/Paris');

if ($argc == 1) {
?>
usage: php $argv[0] [-short] {mdfile} [{fmt}]
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

class Label { // Un label potentiellement dans différentes langues
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

/*YamlDoc: classes
title: class RdfResource - déf. d'une ressource RDF et stockage des prefixes
name: RdfResource
methods:
doc: |
  La classe RdfResource définit la structuration d'un ressource RDF dans le champ $prop.
  Elle définit aussi 3 variables statiques stockant respectivement
   - les prefixes utilisés
   - les Schemas
   - les Concept
  Dans le fichier Yaml en entrée, les concepts peuvent structurés hiérarchiquement,
  le chargement du fichier applatit la hiérarchie en stockant tous les concepts dans self::$concepts
  et en les remplacant dans la hiérarchie par l'URI du concept.
  Le chargement ajoute aussi à chaque concept:
   - le type RDF skos:Concept
   - la propriété skos:inScheme vers le Scheme défini dans Scheme si elle n'est pas définie
*/
class RdfResource {
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
  static array $schemes = []; // [id => ConceptScheme]
  static array $concepts = []; // [id => Concept]
  
  static function defaultSchemeId(): string { return array_keys(self::$schemes)[0]; }
  
  /*YamlDoc: methods
  title: "function __construct(string $id, array $concept, ?string $parent=null)"
  doc: |
    Méthode récursive de création 
    $resource est un array respectant le sous-schéma PredicatesObjects du schéma thespheres.schema.yaml
  */
  function __construct(string $rdfType, string $id, array $resource, ?string $parent=null) {
    $this->prop = ['rdf:type'=> [$rdfType]]; // rajout à chaque ressource de son type RDF
    switch ($rdfType) { // traitement en fonction du type de ressource 
      case 'skos:Concept': {
        if (!isset($resource['skos:inScheme']))
          $this->prop['skos:inScheme'] = [self::defaultSchemeId()];
        if (!$parent) {
          $this->prop['skos:topConceptOf'] = [self::defaultSchemeId()];
          self::$schemes[self::defaultSchemeId()]->prop['skos:hasTopConcept'][] = $id;
        }
        elseif (!isset($resource['skos:inScheme'])) {
          $this->prop['skos:broader'] = [$parent];
        }
        break;
      }
      case 'skos:ConceptScheme': {
        $this->prop['skos:hasTopConcept'] = [];
        break;
      }
    }
    foreach ($resource as $predicate => $objects) {
      if (Label::is($objects)) {
        $this->prop[$predicate] = [new Label($objects)]; // objects ::= Label
      }
      elseif (is_string($objects)) {
        $this->prop[$predicate] = [$objects]; // objects ::= Uri | CompactUri
      }
      elseif (array_is_list($objects)) {
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
      elseif (is_array($objects)) { // objects ::= [{compactUri} => PredicatesObjects]
        $this->prop[$predicate] = [];
        foreach ($objects as $curi => $childPredicatesObjects) {
          self::$concepts[$curi] = new self($rdfType, $curi, $childPredicatesObjects, $id);
          $this->prop[$predicate][] = $curi;
        }
      }
      else
        throw new Exception("cas non prévu");
    }
  }
  
  function asArray(): array {
    $array = [];
    foreach ($this->prop as $predicate => $objects) {
      $props = [];
      foreach ($objects as $object)
        if (is_object($object))
          $props[] = $object->asArray();
        else
          $props[] = $object;
      $array[$predicate] = $props;
    }
    return $array;
  }
  
  static function init(array $yaml, bool $short): void {
    self::$prefix = $yaml['prefix'];
    foreach ($yaml['skos:ConceptScheme'] as $id => $scheme) {
      self::$schemes[$id] = new self('skos:ConceptScheme', $id, $scheme);
    }
    foreach ($yaml['skos:Concept'] as $id => $concept) {
      // création de l'entrée pour le concept afin que le parent soit avant ses enfants
      self::$concepts[$id] = null;
      self::$concepts[$id] = new self('skos:Concept', $id, $concept);
      if ($short) break; // pour limiter à un seul thème de niveau 1 en tests
    }
  }
  
  static function allAsArray(): array {
    $schemes = [];
    foreach (self::$schemes as $id => $scheme) {
      $schemes[$id] = $scheme->asArray();
    }
    $concepts = [];
    foreach (self::$concepts as $id => $concept) {
      $concepts[$id] = $concept->asArray();
    }
    return [
      'prefix'=> self::$prefix,
      'schemes'=> $schemes,
      'concepts'=> $concepts,
    ];
  }
  
  static function asTurtle(string $subject, array $prop): string { // Concept -> Turtle
    unset($prop['@id']);
    //echo "asTurtle($subject, ",Yaml::dump($po),")\n";
    $ttl = "$subject\n";
    foreach ($prop as $predicate => $objects) {
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
        elseif (is_object($object)) {
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

  static function allAsTurtle(): string {
    $ttl = '';
    foreach (self::$prefix as $prefix => $uri)
      $ttl .= "@prefix $prefix: <$uri> .\n";
    $ttl .= "\n";
    foreach (self::$schemes as $uri => $scheme)
      $ttl .= self::asTurtle($uri, $scheme->prop)."\n";
    foreach (self::$concepts as $uri => $concept)
      $ttl .= self::asTurtle($uri, $concept->prop)."\n";
    return $ttl;
  }
}

RdfResource::init(Yaml::parseFile($argv[0]), $short);

// affichage du résultat
if (($argv[1] ?? null) == 'dump') { // dump brut de la structure constuite 
  print_r(['schemes'=> RdfResource::$schemes, 'concepts'=> RdfResource::$concepts]);
  die(beautifulYaml(Yaml::dump(RdfResource::allAsArray(), 5, 2)));
}
elseif (!isset($argv[1])) { // par défaut affichage de la sortie Turtle
  die(RdfResource::allAsTurtle());
}
else { // transformation par EsayRdf en jsonld, yamlld ou autre
  $graph = new \EasyRdf\Graph(RdfResource::defaultSchemeId());
  $graph->parse(RdfResource::allAsTurtle(), 'turtle', RdfResource::defaultSchemeId());
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
