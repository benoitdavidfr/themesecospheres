<?php
// eurovoc/index.php - visualisation d'EuroVoc avec Visualisation à plat des étiquettes et des mots - Benoit David - 19/7/2022
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
    return str_replace(
      [',',"d'","l'",'(',')','«','»'],
      ['', '',  '',  '', '', '', ''],
      $str);
  }
};

abstract class Resource { // une ressource RDF EuroVoc
  protected string $id; // identifiant de la ressource
  protected array $prop; // propriétés telles que stockées dans le fichier Yaml
  
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
  
  function domains(): array { return $this->prop['domain'] ?? []; }
  
  function show(): void { // affichage d'un schéma
    echo "<h3><a href='",$this->uri(),"'>",$this->prefLabel('fr'),"</a> ($this->id)</h3>\n";
    $prop = $this->prop;
    foreach ($prop['domain'] ?? [] as $no => $id)
      $prop['domain'][$no] = "<a href=\"?action=show&type=domain&id=$id\">".Domain::$all[$id]->prefLabel('fr')."<a>";
    foreach ($prop['hasTopConcept'] ?? [] as $no => $id)
      $prop['hasTopConcept'][$no] = "<a href=\"?action=show&type=concept&id=$id\">".Concept::$all[$id]->prefLabel('fr')."<a>";
    echo '<pre>',Yaml::dump($prop),"</pre>\n";
  }

  function showHierarchy(): void { // affichage hiérarchique 
    echo "<h4><a href='?action=show&amp;type=scheme&amp;id=$this->id'>",$this->prefLabel('fr'),"</a></h4><ul>\n";
    //if (0)
    foreach ($this->prop['hasTopConcept'] as $id)
      Concept::$all[$id]->showHierarchy();
    echo "</ul>\n";
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

  function showHierarchy(): void { // affichage hiérarchique 
    echo "<li><a href='?action=show&amp;type=concept&amp;id=$this->id'>",$this->prefLabel('fr'),"</a><ul>\n";
    foreach ($this->prop['narrower'] ?? [] as $id)
      Concept::$all[$id]->showHierarchy();
    echo "</ul></li>\n";
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
    echo "<h2>Etiquettes</h2><ul>\n";
    //echo "<pre>"; print_r(self::labels());
    foreach (self::labels() as $labelIds)
      echo "<li><a href='?action=show&type=concepts&ids=",implode(',', $labelIds->ids()),"'>",$labelIds->label(),"</a></li>\n";
    echo "</ul>\n";
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
    echo "<h2>Liste des mots extraits des étiquettes</h2><ul>\n";
    foreach ($words as $wordIds)
      echo "<li><a href='?action=show&type=concepts&ids=",implode(',', $wordIds->ids()),"'> ",$wordIds->label()," </a></li>\n";
  }
};

class Domain extends Concept { // Un domaine regroupe des schemes 
  protected array $schemes; // liste des id de scheme rattachés à ce domaine

  static array $all; // liste des domaines [id => Domain]
  
  function addScheme(string $schemeId): void { $this->schemes[] = $schemeId; }
  
  static function showAll() {
    foreach (self::$all as $id => $domain) {
      $domain->show();
    }
  }
  
  function show(): void { // affiche un domaine
    echo "<h3>",$this->prefLabel('fr'),"</h3><ul>\n";
    foreach ($this->schemes as $schId)
      echo "<li><a href='?action=show&amp;type=scheme&amp;id=$schId'>",Scheme::$all[$schId]->prefLabel('fr'),"</a></li>\n";
    echo "</ul>\n";
  }
  
  static function showAllHierarchy(): void {
    echo "<h2>Affichage hiérarchique des domaines, schémas et concepts</h2>\n";
    foreach (self::$all as $domain) {
      $domain->showHierarchy();
    }
  }
  
  function showHierarchy(): void { // affichage hiérarchique 
    echo "<h3><a href='?action=show&amp;type=domain&amp;id=$this->id'>",$this->prefLabel('fr'),"</a></h3>\n";
    foreach ($this->schemes as $id)
      Scheme::$all[$id]->showHierarchy();
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
    uasort(Domain::$all, function(Domain $a, Domain $b): int { return strcmp($a->prefLabel('fr'), $b->prefLabel('fr')); });
    
    foreach ($yaml['schemes'] as $id => $prop) {
      if ($id <> 'candidates')
        Scheme::$all[$id] = new Scheme($id, $yaml['schemes'][$id]);
    }
    uasort(Scheme::$all, function(Scheme $a, Scheme $b): int { return strcmp($a->prefLabel('fr'), $b->prefLabel('fr')); });
    // affectation des schemes à leur domaine maintenant qu'il sont dans l'ordre
    foreach (Scheme::$all as $schId => $scheme) {
      foreach ($scheme->domains() as $domain)
        Domain::$all[$domain]->addScheme($schId);
    }
    
    foreach ($yaml['concepts'] as $id => $prop) {
      Concept::$all[$id] = new Concept($id, $yaml['concepts'][$id]);
    }

    //if (0)
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
        echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>EuroVoc</title></head><body>\n";
        echo "<h3>Menu</h3><ul>\n";
        echo "<li><a href='?action=show&type=allDomains'>Navigation interactive</a></li>\n";
        echo "<li><a href='?action=show&type=hierarchy'>Affichage de la hiérarchie Domaines/Schémas/Concepts</a></li>\n";
        echo "<li><a href='?action=show&type=labels'>Affichage à plat des étiquettes</a></li>\n";
        echo "<li><a href='?action=show&type=words'>Affichage des mots extraits des étiquettes</a></li>\n";
        echo "</ul>\n";
        break;
      }
      case 'show': { // cas de l'action show en fonction de quoi 
        switch ($_GET['type']) {
          case 'allDomains': {
            echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>EuroVoc/domaines</title></head><body>\n";
            Domain::showAll();
            break;
          }
          case 'domain': {
            echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>EuroVoc/domaine</title></head><body>\n";
            Domain::$all[$_GET['id']]->show();
            break;
          }
          case 'scheme': {
            echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>EuroVoc/schéma</title></head><body>\n";
            Scheme::$all[$_GET['id']]->show();
            break;
          }
          case 'concept': {
            echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>EuroVoc/concept</title></head><body>\n";
            Concept::$all[$_GET['id']]->show();
            break;
          }
          case 'concepts': { // affichage d'une liste de concepts définis par leur id
            $ids = explode(',', $_GET['ids']);
            if (count($ids) == 1) {
              echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>EuroVoc/concept</title></head><body>\n";
              Concept::$all[$ids[0]]->show();
            }
            else {
              echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>EuroVoc/concepts</title></head><body>\n";
              echo "<h2>Concepts</h2><ul>\n";
              foreach ($ids as $id)
                echo "<li><a href='?action=show&type=concept&id=$id'>",Concept::$all[$id]->prefLabel('fr'),"</a></li>\n";
              echo "</ul>\n";
            }
            break;
          }
          case 'labels': {
            echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>EuroVoc/labels</title></head><body>\n";
            Concept::showLabels();
            break;
          }
          case 'words': {
            echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>EuroVoc/words</title></head><body>\n";
            Concept::showWords();
            break;
          }
          case 'hierarchy': {
            echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>EuroVoc/Hiérarchie</title></head><body>\n";
            Domain::showAllHierarchy();
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
