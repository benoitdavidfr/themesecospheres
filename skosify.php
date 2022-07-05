<?php
/*YamlDoc:
title: skosify.php - génération d'une version Skos des thèmes Ecosphères
name: skosify.php
doc: |
  Lit le fichier des thèmes en MarkDown en paramètre et fabrique le fichier Skos correspondant.
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

$themes = file_get_contents($argv[0]);
$themes = explode("\n", $themes);

//echo "<pre>\n";
//print_r($themes);

$prefixes = [
  'skos'=> 'http://www.w3.org/2004/02/skos/core#',
  'rdf'=> 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
  'dct'=> 'http://purl.org/dc/terms/',
  'ecospheres'=> 'http://registre.data.developpement-durable.gouv.fr/ecospheres/',
  'themes'=> 'http://registre.data.developpement-durable.gouv.fr/ecospheres/themes-ecospheres/',
  'espsyntax'=> 'http://registre.data.developpement-durable.gouv.fr/ecospheres/syntax#',
];

$scheme = [
  '@id'=> ['ecospheres:themes'],
  'rdf:type'=> ['skos:ConceptScheme'],
  'dct:title'=> [],
  'dct:description'=> [],
  'dct:modified'=> ['"'.date(DATE_ATOM).'"'],
  'dct:license'=> ['https://www.etalab.gouv.fr/licence-ouverte-open-licence'],
  'skos:hasTopConcept'=> [],
];

$concepts = []; // [{subject}=> [{predicate}=> [{object}]]]

// construction de $concepts à partir du fichier MD
foreach ($themes as $theme) {
  $pattern = '!^\[([^\]]+)\]\(([^)]+)\)$!';
  if (substr($theme, 0, 2)=='# ') { // Titre du document
    $scheme['dct:title'] = [substr($theme, 2)];
  }
  elseif (substr($theme, 0, 5)=='#### ') { // Description du document
    $scheme['dct:description'] = [substr($theme, 5)];
  }
  elseif (substr($theme, 0, 3)=='## ') { // theme de niveau 1 
    if ($short && $concepts) break; // limite à un seul topConcept pour les tests
    $theme = substr($theme, 3);
    if (!preg_match($pattern, $theme, $matches)) {
      die("No match pour $theme\n");
    }
    $theme = $matches[1];
    $puri = $matches[2];
    $puri = str_replace('http://registre.data.developpement-durable.gouv.fr/ecospheres/themes-ecospheres/','themes:', $puri);
    $concepts[$puri] = [
      'rdf:type'=> ['skos:Concept'],
      'skos:inScheme'=> ['ecospheres:themes'],
      'skos:topConceptOf'=> ['ecospheres:themes'],
      'skos:prefLabel'=> [$theme],
      'skos:altLabel'=> [],
      'espsyntax:regexp'=> [],
      'skos:narrower'=> [],
    ];
    $scheme['skos:hasTopConcept'][] = $puri;
  }
  elseif (substr($theme, 0, 2)=='- ') { // theme de niveau 2
    $theme = substr($theme, 2);
    if (!preg_match($pattern, $theme, $matches))
      die("No match pour $theme\n");
    $theme = $matches[1];
    $uri = $matches[2];
    $uri = str_replace('http://registre.data.developpement-durable.gouv.fr/ecospheres/themes-ecospheres/','themes:', $uri);
    $concepts[$uri] = [
      'rdf:type'=> ['skos:Concept'],
      'skos:inScheme'=> ['ecospheres:themes'],
      'skos:prefLabel'=> [$theme],
      'skos:altLabel'=> [],
      'espsyntax:regexp'=> [],
      'skos:broader'=> [$puri],
    ];
    $concepts[$puri]['skos:narrower'][] = $uri;
  }
}

// ajout de qqs altLabels et regexps
if (1) {
  $concepts['themes:climat']['skos:altLabel'] = ['air-climat'];
  $concepts['themes:changement-climatique']['skos:altLabel'] = [
    'Air Climat/Changement climatique',
    'climat-air-et-energie',
    'climat',
  ];
  $concepts['themes:changement-climatique']['espsyntax:regexp'] = [
    'Plans? Climat-Energie',
    'Plan Climat Air Energie territorr?ial',
    'Air, Énergie et Climat',
    'PCAET',
    "Plans de Protection de l'Atmosphère",
    'SRCAE',
    'territoires? à énergie positive',
  ];
  $concepts['themes:meteorologie']['skos:altLabel'] = [
    'Air Climat/Météo',
  ];
}


function ttlify(string $subject, array $po): string {
  unset($po['@id']);
  $ttl = "$subject\n";
  foreach ($po as $predicate => $objects) {
    if (!$objects) continue;
    $ttl .= "  $predicate ";
    foreach ($objects as $io => $object) {
      if ((substr($object, 0, 7)=='http://') || (substr($object, 0, 8)=='https://'))
        $ttl .= "<$object>";
      elseif (strpos($object, ':')==false)
        $ttl .= "\"$object\"@fr";
      else
        $ttl .= "$object";
      if ($io <> (count($objects)-1))
        $ttl .= ", ";
      else
        $ttl .= ";\n";
    }
  }
  $ttl = substr($ttl, 0, -2);
  return "$ttl.\n";
}

function beautifulYaml(string $yamlText): string {
  //return $yamlText;
  return preg_replace('!-\n +!', '- ', $yamlText);
}

// affichage du résultat
if (($argv[1] ?? null) == 'dump') { // dump brut de la structure constuite 
  echo Yaml::dump(['prefixes'=> $prefixes, 'scheme'=> $scheme, 'concepts'=> $concepts], 6, 2);
  die();
}
else { // fabrication d'une sortie Turtle
  $ttl = '';
  foreach ($prefixes as $prefix => $uri)
    $ttl .= "@prefix $prefix: <$uri> .\n";
  $ttl .= "\n";
  $ttl .= ttlify($scheme['@id'][0], $scheme)."\n";
  foreach ($concepts as $uri => $po)
    $ttl .= ttlify($uri, $po)."\n";
  if (!isset($argv[1])) { // par défaut affichage de la sortie Turtle
    die($ttl);
  }
  else { // transformation par EsayRdf en jsonld, yamlld ou autre
    $graph = new \EasyRdf\Graph($scheme['@id'][0]);
    $graph->parse($ttl, 'turtle', $scheme['@id'][0]);
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
}
