<?php
/*YamlDoc:
title: skosify.php - génération d'une version Skos des thèmes Ecosphères
name: skosify.php
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
date_default_timezone_set('Europe/Paris');

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


if (!function_exists('array_is_list')) {
  function array_is_list($list): bool { return is_array($list) && !is_assoc_array($list); }
}

function array_is_dict(array $array): bool {
  return count(array_diff_key($array, array_keys(array_keys($array))));
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
};

class Concept { // stockage du scheme Skos
  protected array $prop; // [{predicat} => [{object}]] où {object} ::= URI | compactURI | Label
  static array $prefix = [];
  static array $scheme = [];
  static array $concepts = []; // [id => Concept]
  
  function __construct(string $id, array $concept, ?string $parent=null) {
    $this->prop = [
      'rdf:type'=> ['skos:Concept'],
      'skos:inScheme'=> self::$scheme['@id'],
    ];
    if (!$parent) {
      $this->prop['skos:topConceptOf'] = self::$scheme['@id'];
      self::$scheme['skos:hasTopConcept'][] = $id;
    }
    else {
      $this->prop['skos:broader'] = [$parent];
    }
    
    $narrower = [];
    foreach ($concept['skos:narrower'] ?? [] as $cid => $child) {
      self::$concepts[$cid] = new self($cid, $child, $id);
      $narrower[] = $cid;
    }
    unset($concept['skos:narrower']);
    
    foreach ($concept as $predicate => $objects) {
      if (Label::is($objects))
        $this->prop[$predicate] = [new Label($objects)];
      elseif (array_is_list($objects)) {
        $this->prop[$predicate] = [];
        foreach ($objects as $object) {
          if (Label::is($object))
            $this->prop[$predicate][] = new Label($object );
        }
      }
    }
    
    $this->prop['skos:narrower'] = $narrower;
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
  
  static function init(array $yaml): void {
    self::$prefix = $yaml['prefix'];
    foreach ($yaml['skos:ConceptScheme'] as $pred => $objects) {
      if (Label::is($objects))
        self::$scheme[$pred] = [new Label($objects)];
      elseif (is_string($objects))
        self::$scheme[$pred] = [$objects];
    }
    self::$scheme['skos:hasTopConcept'] = [];
    foreach ($yaml['skos:Concept'] as $id => $concept) {
      self::$concepts[$id] = null;
      self::$concepts[$id] = new Concept($id, $concept);
      break;
    }
  }
  
  static function allAsArray(): array {
    $concepts = [];
    foreach (self::$concepts as $id => $concept) {
      $concepts[$id] = $concept->asArray();
    }
    return [
      //'prefix'=> self::$prefix,
      //'scheme'=> self::$scheme,
      'concepts'=> $concepts,
    ];
  }
  
  
  static function labelAsTurtle(array $label): string {
    $ttl = '';
    foreach ($label as $lang => $string)
      $ttl .= "\"$string\"".($lang<>'x' ? "@$lang" : '');
    return $ttl;
  }
  
  static function asTurtle(string $subject, array $po): string {
    unset($po['@id']);
    echo "asTurtle($subject, ",Yaml::dump($po),")\n";
    $ttl = "$subject\n";
    foreach ($po as $predicate => $objects) {
      if (!$objects) continue;
      if (self::isLabel($objects)) {
        $ttl .= "  $predicate ".self::labelAsTurtle($objects).";\n";
        continue;
      }
      $ttl .= "  $predicate ";
      foreach ($objects as $io => $object) {
        if (is_string($object)) {
          if ((substr($object, 0, 7)=='http://') || (substr($object, 0, 8)=='https://')) // URI
            $ttl .= "<$object>";
          elseif (strpos($object, ':') !== false) // URI compacté
            $ttl .= "$object";
          else {
            throw new Exception("Objet \"$object\" non interprété");
          }
        }
        elseif (is_array($object)) {
          if (array_keys($object)[0] == 0) { // liste de libellés
            foreach ($object as $label) {
              foreach ($label as $lang => $string)
                $ttl .= "\"$string\"@$lang";
            }
          }
          else { // un label
            foreach ($object as $lang => $string)
              $ttl .= "\"$string\"@$lang";
          }
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
    $ttl .= self::asTurtle(self::$scheme['@id'], self::$scheme)."\n";
    return $ttl;
    foreach ($concepts as $uri => $po)
      $ttl .= ttlify($uri, $po)."\n";
    return $ttl;
  }
}

Concept::init(Yaml::parseFile($argv[0]));

// affichage du résultat
if (($argv[1] ?? null) == 'dump') { // dump brut de la structure constuite 
  print_r(['scheme'=> Concept::$scheme, 'concepts'=> Concept::$concepts]);
  die(beautifulYaml(Yaml::dump(Concept::allAsArray(), 5, 2)));
}
elseif (!isset($argv[1])) { // par défaut affichage de la sortie Turtle
  die(Concept::allAsTurtle());
}
else { // transformation par EsayRdf en jsonld, yamlld ou autre
  $graph = new \EasyRdf\Graph($scheme['@id'][0]);
  $graph->parse(Concept::allAsTurtle(), 'turtle', $scheme['@id'][0]);
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
      $compacted = ML\JsonLD\JsonLD::compact($ser, json_encode($prefixes));
      echo json_encode($compacted, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
      break;
    }
    case 'cyamlld': {
      $ser = $graph->serialise('jsonld');
      $compacted = ML\JsonLD\JsonLD::compact($ser, json_encode($prefixes));
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
