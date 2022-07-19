<?php
// eurovoc/index.php - visualisation d'EuroVoc avec Visualisation à plat des étiquettes et des mots - Benoit David - 18/7/2022
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

class Str { // Fonctions sur string
  const EmptyWords = ['de','des','-','—']; // mots sans signification
  
  static function tolower(string $str): string { // passage en minuscule sans accents 
    return str_replace(
        ['â','Å','é','É','î','Î','Ö','œ'],
        ['a','a','e','e','i','i','o','oe'],
        strtolower($str));
  }
  
  static function clean(string $str): string { // supprime qqs caractères parasites pour construire les mots
    return str_replace([',',"d'",'(',')','«','»'], ['','','',''], $str);
  }
};

abstract class Resource { // une ressource RDF EuroVoc
  protected string $id; // identifiant de la ressource
  protected array $prop; // propriétés telles que stockées dans le fichier
  
  function __construct(string $id, array $prop) { $this->id = $id; $this->prop = $prop; }
  
  function uri(): string { return 'http://eurovoc.europa.eu/'.$this->id; }
  function prefLabel(string $lang): string { return $this->prop['prefLabel'][$lang]; }
  function altLabel(string $lang): array { return $this->prop['altLabel'][$lang] ?? []; }
  function hasTopConcept(): array { return $this->prop['hasTopConcept']; }
  
  function show(): void { // visualisation par défaut d'une ressource, pas utilisée 
    echo "<h3>",$this->prefLabel('fr'),"</h3><ul>\n";
    $prop = $this->prop;
    echo '<pre>',Yaml::dump($prop),"</pre>\n";
  }
};

class Scheme extends Resource { // schéma de concepts 
  static array $all; // dict. des schemas [id => Scheme]
  
  function show(): void { // visualisation d'un schéma
    echo "<h3><a href='",$this->uri(),"'>",$this->prefLabel('fr'),"</a> ($this->id)</h3>\n";
    $prop = $this->prop;
    foreach ($prop['domain'] ?? [] as $no => $id)
      $prop['domain'][$no] = "<a href=\"?action=show&type=domain&id=$id\">".Domain::$all[$id]->prefLabel('fr')."<a>";
    foreach ($prop['hasTopConcept'] ?? [] as $no => $id)
      $prop['hasTopConcept'][$no] = "<a href=\"?action=show&type=concept&id=$id\">".Concept::$all[$id]->prefLabel('fr')."<a>";
    echo '<pre>',Yaml::dump($prop),"</pre>\n";
  }
};

class LabelIds { // l'association d'une étiquette à une liste d'ids
  protected string $label;
  protected array $ids;
  
  function __construct(string $label, array $ids) { $this->label = $label; $this->ids = $ids; }
  
  function label(): string { return $this->label; }
  function ids(): array { return $this->ids; }

  function addIds(array $ids): void {
    foreach ($ids as $id)
      if (!in_array($id, $this->ids))
        $this->ids[] = $id;
  }
};

class Concept extends Resource { // un concept Skos 
  static array $all; // dict. des concepts [id => Concept]

  function show(): void { // Affichage d'un concept 
    echo "<h3><a href='",$this->uri(),"'>",$this->prefLabel('fr'),"</a> ($this->id)</h3>\n";
    //echo '<pre>',Yaml::dump($this->prop),"</pre>\n";
    $prop = $this->prop;
    foreach ($prop['inScheme'] ?? [] as $no => $cId)
      $prop['inScheme'][$no] = "<a href=\"?action=show&type=scheme&id=$cId\">".Scheme::$all[$cId]->prefLabel('fr')."<a>";
    foreach ($prop['topConceptOf'] ?? [] as $no => $cId)
      $prop['topConceptOf'][$no] = "<a href=\"?action=show&type=scheme&id=$cId\">".Scheme::$all[$cId]->prefLabel('fr')."<a>";
    foreach ($prop['broader'] ?? [] as $no => $cId)
      $prop['broader'][$no] = "<a href=\"?action=show&type=concept&id=$cId\">".Concept::$all[$cId]->prefLabel('fr')."<a>";
    foreach ($prop['narrower'] ?? [] as $no => $cId)
      $prop['narrower'][$no] = "<a href=\"?action=show&type=concept&id=$cId\">".Concept::$all[$cId]->prefLabel('fr')."<a>";
    foreach ($prop['related'] ?? [] as $no => $cId)
      $prop['related'][$no] = "<a href=\"?action=show&type=concept&id=$cId\">".Concept::$all[$cId]->prefLabel('fr')."<a>";
    echo '<pre>',Yaml::dump($prop, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
  }

  static function labels(): array { // fabrique la liste triée des étiquettes sous la forme [{lowerLabel} => LabelIds]
    $labels = []; // [{lowerLabel} => LabelIds]
    foreach (self::$all as $id => $c) {
      $label = $c->prefLabel('fr');
      $lowLabel = Str::tolower($label);
      if (!isset($labels[$lowLabel]))
        $labels[$lowLabel] = new LabelIds($label, [$id]);
      else
        $labels[$lowLabel]->addIds([$id]);
      foreach ($c->altLabel('fr') as $label) {
        $lowLabel = Str::tolower($label);
        if (!isset($labels[$lowLabel]))
          $labels[$lowLabel] = new LabelIds($label, [$id]);
        else
          $labels[$lowLabel]->addIds([$id]);
      }
    }
    ksort($labels);
    return $labels;
  }
  
  static function showLabels(): void { // affiche les étiquettes triées alphabétiquement
    //echo "<pre>"; print_r(self::labels());
    foreach (self::labels() as $labelIds) {
      echo "<a href='?action=show&type=concepts&ids=",implode(',', $labelIds->ids()),"'>",$labelIds->label(),"</a><br>\n";
    }
  }
  
  static function showWords(): void { // affiche les mots contenus dans les étiquettes triés
    $words = []; // [{lowerWord} => ['ids'=> [{id}], 'origin'=> [{word}]]]
    foreach (self::labels() as $labelIds) {
      foreach (explode(' ', Str::clean($labelIds->label())) as $word) {
        $lowWord = strtolower($word);
        if (!isset($words[$lowWord]))
          $words[$lowWord] = new LabelIds($word, $labelIds->ids());
        else
          $words[$lowWord]->addIds($labelIds->ids());
      }
    }
    foreach (Str::EmptyWords as $emptyWord)
      unset($words[$emptyWord]);
    ksort($words);
    foreach ($words as $wordIds) {
      echo "<a href='?action=show&type=concepts&ids=",implode(',', $wordIds->ids()),"'> ",$wordIds->label()," </a><br>\n";
    }
  }
};

class Domain extends Concept { // Un domaine regroupe des schemes 
  protected array $schemes; // liste des id de scheme rattachés à ce domaine

  static array $all; // liste des micro-thésaurus [id => Domain]
  
  function addScheme(string $schemeId): void { $this->schemes[] = $schemeId; }
  
  static function showAll() {
    foreach (self::$all as $id => $domain) {
      $domain->show();
    }
  }
  
  function show(): void {
    echo "<h3>",$this->prefLabel('fr'),"</h3><ul>\n";
    foreach ($this->schemes as $schId)
      echo "<li><a href='?action=show&amp;type=scheme&amp;id=$schId'>",Scheme::$all[$schId]->prefLabel('fr'),"</a></li>\n";
    echo "</ul>\n";
  }
};

class EuroVoc {
  static function init(): void { // initialisation de la structure à partir du fichier yaml avec création d'un fichier pser 
    if (is_file(__DIR__.'/eurovoc.pser')) {
      $ser = unserialize(file_get_contents(__DIR__.'/eurovoc.pser'));
      Domain::$all = $ser['domains'];
      Scheme::$all = $ser['schemes'];
      Concept::$all = $ser['concepts'];
      return;
    }
    //$yaml = Yaml::parseFile(__DIR__.'/../../yamldoc/pub/eurovoc.yaml');
    $yaml = Yaml::parseFile(__DIR__.'/eurovoc.yaml');
    foreach ($yaml['domainScheme']['hasTopConcept'] as $id) {
      Domain::$all[$id] = new Domain($id, $yaml['domains'][$id]);
    }
    foreach ($yaml['schemes'] as $id => $prop) {
      Scheme::$all[$id] = new Scheme($id, $yaml['schemes'][$id]);
      foreach ($yaml['schemes'][$id]['domain'] ?? [] as $domain) {
        Domain::$all[$domain]->addScheme($id);
      }
    }
    foreach ($yaml['concepts'] as $id => $prop) {
      Concept::$all[$id] = new Concept($id, $yaml['concepts'][$id]);
    }
    file_put_contents(__DIR__.'/eurovoc.pser',
      serialize([
        'domains'=> Domain::$all,
        'schemes'=> Scheme::$all,
        'concepts'=> Concept::$all,
      ])
    );
  }

  static function action(): void { // les actions à exécuter 
    switch ($_GET['action'] ?? null) {
      case null: { // cas où aucune action n'est définie 
        echo "<a href='?action=show&type=allDomains'>Visualisation hiérarchique</a><br>\n";
        echo "<a href='?action=show&type=labels'>Visualisation à plat des étiquettes</a><br>\n";
        echo "<a href='?action=show&type=words'>Visualisation des mots</a><br>\n";
        break;
      }
      case 'show': { // cas de l'action show en fonction de quoi 
        switch ($_GET['type']) {
          case 'allDomains': {
            Domain::showAll();
            break;
          }
          case 'domain': {
            Domain::$all[$_GET['id']]->show();
            break;
          }
          case 'scheme': {
            Scheme::$all[$_GET['id']]->show();
            break;
          }
          case 'concept': {
            Concept::$all[$_GET['id']]->show();
            break;
          }
          case 'concepts': {
            $ids = explode(',', $_GET['ids']);
            if (count($ids) == 1)
              Concept::$all[$ids[0]]->show();
            else {
              foreach ($ids as $id) {
                echo "<a href='?action=show&type=concept&id=$id'>",Concept::$all[$id]->prefLabel('fr'),"</a><br>\n";
              }
            }
            break;
          }
          case 'labels': {
            Concept::showLabels();
            break;
          }
          case 'words': {
            Concept::showWords();
            break;
          }
          default: {
            throw new Exception("NON PREVU");
          }
        }
      }
    }
  }
};

EuroVoc::init();
EuroVoc::action();
