<?php
/*YamlDoc:
title: skosify.php - génération d'une version Skos des thèmes Ecosphères
name: skosify.php
doc: |
  Lit le fichier des thèmes en MarkDown en paramètre et fabrique le fichier ttl correspondant.
journal: |
  5/7/2022:
    - création
*/
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// Définit le fuseau horaire par défaut à utiliser.
date_default_timezone_set('Europe/Paris');

if ($argc == 1) {
  echo "usage: php $argv[0] {mdfile} [{fmt}]\n";
  print_r(\EasyRdf\Format::getFormats());
  die();
}

$themes = file_get_contents($argv[1]);
$themes = explode("\n", $themes);

//echo "<pre>\n";
//print_r($themes);

$prefixes = [
  'skos'=> 'http://www.w3.org/2004/02/skos/core#',
  'rdf'=> 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
  'dct'=> 'http://purl.org/dc/terms/',
  'ecospheres'=> 'http://registre.data.developpement-durable.gouv.fr/ecospheres/',
  'themes'=> 'http://registre.data.developpement-durable.gouv.fr/ecospheres/themes-ecospheres/',
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
  elseif (substr($theme, 0, 5)=='#### ') { // Titre du document
    $scheme['dct:description'] = [substr($theme, 5)];
  }
  elseif (substr($theme, 0, 3)=='## ') { // theme de niveau 1 
    if ($concepts) break; // limite à un seul topConcept pour les tests
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
      'skos:broader'=> [$puri],
    ];
    $concepts[$puri]['skos:narrower'][] = $uri;
  }
}

function ttlify(string $subject, array $po): string {
  unset($po['@id']);
  $ttl = "$subject\n";
  foreach ($po as $predicate => $objects) {
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

if (0) {
  echo Yaml::dump($concepts, 6, 2);
}
else {
  $ttl = '';
  foreach ($prefixes as $prefix => $uri)
    $ttl .= "@prefix $prefix: <$uri> .\n";
  $ttl .= "\n";
  $ttl .= ttlify($scheme['@id'][0], $scheme)."\n";
  foreach ($concepts as $uri => $po)
    $ttl .= ttlify($uri, $po)."\n";
  if (0) {
    die($ttl);
  }
  else {
    $graph = new \EasyRdf\Graph($scheme['@id'][0]);
    $graph->parse($ttl, 'turtle', $scheme['@id'][0]);
     switch ($ofmt = $argv[2] ?? 'turtle') {
      case 'jsonld': {
        $ser = $graph->serialise($ofmt);
        echo json_encode(json_decode($ser), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        break;
      }
      case 'yamlld': {
        $ser = $graph->serialise('jsonld');
        echo Yaml::dump(json_decode($ser, true), 6, 2);
        break;
      }
      default: {
        $ser = $graph->serialise($ofmt);
        echo is_string($ser) ? $ser : Yaml::dump($ser, 6, 2);
        break;
      }
    }
  }
}
