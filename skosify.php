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
    foreach ($this->strings as $lang => $string) {
      $string = str_replace('"', '\"', $string); // échappement des '""
      $ttl .= "\"$string\"".($lang<>'x' ? "@$lang" : '').', ';
    }
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
  const PhpClassNameForRdfType = [
    'foaf:Person'=> 'FoafPerson',
  ];
  const RegistreUri = 'http://registre/'; // Test en dev
  //const RegistreUri = 'http://registre.georef.eu/'; // Registre provisoire sur georef
  
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
          // Si le rdf:type est défini alors il est utilisé pour définir la classe de l'objet sinon c'est la classe du père
          $className = isset($childPredicatesObjects['rdf:type']) ?
            self::PhpClassNameForRdfType[$childPredicatesObjects['rdf:type']]
            : get_class($this);
          new $className($curi, $childPredicatesObjects, $id);
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
    foreach (self::$prefix as $name => &$uri) {
      $uri = str_replace('http://registre.data.developpement-durable.gouv.fr/', self::RegistreUri, $uri);
    }
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
          else
            throw new Exception("Objet \"$object\" non interprété");
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
  
  static function allAsRegistre(): array { // export selon le format de chargement du registre
    $export = [
      'title'=> "Définition des thèmes Ecopsphères dans le format d'import du registre",
      'abstract'=> "Ce fichier comporte 8 chapitres:\n"
        ."  - d'une part 4 chapitres définissant des ressources:"
        ."    - le chapitre registres définissant le registre Ecosphères et un sous-registre de personnes citées,"
        ."    - le chapitre schemes définit les schéms de concepts,"
        ."    - le chapitre persons définit les personnes citées,"
        ."    - le chapitre concepts définissant les thèmes comme concepts,"
        ."  - d'autre part correspondant à chacun des 4 premiers chapitres, un chapitre supprimant les ressources définies.",
      '$schema'=> 'upload',
      'chapters'=> [
        'registres'=> [
          'title'=> "Registres Ecosphère(s) et des personnes + déf. des propriétés RDF spécifiques",
          'put'=> [
            '/ecospheres'=> [
              'parent'=> '',
              'type'=> 'R',
              'title'=> 'Registre Ecosphère(s)',
            ],
            '/ecospheres/persons'=> [
              'parent'=> '/ecospheres',
              'type'=> 'R',
              'title'=> 'Registre Ecosphère(s) des personnes',
            ],
            '/ecospheres/syntax'=> [
              'parent'=> '/ecospheres',
              'type'=> 'E',
              'title'=> 'Définition des propriétés RDF spécifiques à Ecosphères (en cours)',
              'htmlval'=> "<!DOCTYPE HTML><html><head><meta charset='UTF-8'>"
                ."<title>Propriétés RDF spécifiques à Ecosphères</title></head><body>"
                ."<div id='regexp'><code>http://registre/ecospheres/syntax#regexp</code>"
                  ." Associe une expression régulière à un thème Ecosphères.</div>"
                ."</body>",
            ],
          ],
        ],
        'schemes'=> [
          'title'=> "les schémas des concepts",
          'put'=> [],
        ],
        'persons'=> [
          'title'=> "Les personnes",
          'put'=> [],
        ],
        'concepts'=> [
          'title'=> "les concepts",
          'put'=> [],
        ],
        'deleteConcepts'=> [
          'title'=> "Suppression des concepts",
          'delete'=> [],
        ],
        'deleteSchemes'=> [
          'title'=> "Suppression des schéma des concepts",
          'delete'=> [],
        ],
        'deletePersons'=> [
          'title'=> "Suppression des personnes",
          'delete'=> [],
        ],
        'deleteRegistres'=> [
          'title'=> "Suppression des registres Ecosphères et des personnes",
          'delete'=> [
            '/ecospheres/syntax',
            '/ecospheres/persons',
            '/ecospheres',  
          ],
        ],
      ],
    ];
    
    foreach (Scheme::$all as $schemeId => $scheme) {
      $path = str_replace(
        ['ecospheres:', 'eurovoc:', 'persons:'],
        ['/ecospheres/', '/ecospheres/eurovoc/','/ecospheres/persons/'],
        $schemeId);
      $export['chapters']['schemes']['put'][$path] = Scheme::$all[$schemeId]->asRegistre($schemeId);
      $export['chapters']['deleteSchemes']['delete'][] = $path;
    }
    
    foreach (FoafPerson::$all as $id => $person) {
      $path = str_replace(['persons:'], ['/ecospheres/persons/'], $id);
      $export['chapters']['persons']['put'][$path] = $person->asRegistre($id);
      $export['chapters']['deletePersons']['delete'][] = $path;
    }
    
    foreach (Concept::$all as $id => $concept) {
      $path = str_replace(
        ['themes:', 'eurovoc:'],
        ['/ecospheres/themes-ecospheres/', '/ecospheres/eurovoc/'],
        $id);
      $export['chapters']['concepts']['put'][$path] = $concept->asRegistre($id);
      $export['chapters']['deleteConcepts']['delete'][] = $path;
      //break;
    }
    return $export;
  }
  
  function asHttl(string $id) { // Hyper Turtle
    $ttl = str_replace('<','&lt;', $this->asTurtle($id));
    foreach (self::$prefix as $prefix => $uri)
      $ttl = preg_replace("!($prefix:([^ \\n;,\\.]+))!", "<a href='$uri$2'>$1</a>", $ttl);
    return "<pre>$ttl</pre>\n";
  }
  
  function asRegistre(string $id): array {
    $ttl = '';
    foreach (self::$prefix as $prefix => $uri)
      $ttl .= "@prefix $prefix: <$uri> .\n";
    $ttl .= "\n";
    $ttl .= $this->asTurtle($id);
    //die($ttl);
    $graph = new \EasyRdf\Graph(Scheme::defaultSchemeId());
    $graph->parse($ttl, 'turtle', Scheme::defaultSchemeId());
    $ser = $graph->serialise('jsonld');
    $compacted = ML\JsonLD\JsonLD::compact($ser, json_encode(RdfResource::$prefix));
    //echo beautifulYaml(Yaml::dump(json_decode(json_encode($compacted), true), 4, 2)),"\n";
    return [
      'parent'=> $this->parent(),
      'type'=> 'E',
      'title'=> $this->title(),
      'jsonval'=> json_decode(json_encode($compacted), true),
      'htmlval'=> $this->asHttl($id),
    ];
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

  function title(): string { return $this->prop['dct:title'][0]->asArray()['fr']; }
  function parent(): string { return '/ecospheres'; }
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

  function title(): string { return $this->prop['skos:prefLabel'][0]->asArray()['fr']; }
  
  function parent(): string { 
    if ($this->prop['skos:inScheme'][0] == 'ecospheres:themes-ecospheres')
      return '/ecospheres/themes-ecospheres';
    elseif ($this->prop['skos:inScheme'][0] == 'eurovoc:100141')
      return '/ecospheres/eurovoc/100141';
  }
};

class FoafPerson extends RdfResource {
  const Type = 'foaf:Person';
  
  static array $all = []; // [id => FoafPerson] - toutes les personnes

  function __construct(string $id, array $resource, ?string $parent=null) {
    self::$all[$id] = $this;
    parent::__construct($id, $resource, $parent);
  }

  function title(): string { return $this->prop['foaf:name'][0]->asArray()['x']; }
  function parent(): string { return '/ecospheres/persons'; }
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
    case 'registre': {
      die(Yaml::dump(RdfResource::allAsRegistre(), 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
    }
    default: {
      $ser = $graph->serialise($ofmt);
      echo is_string($ser) ? $ser : beautifulYaml(Yaml::dump($ser, 6, 2));
      break;
    }
  }
}
