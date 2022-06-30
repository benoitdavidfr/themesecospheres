<?php

/* Fonction de slugification
   src: https://cogito-ergo-dev.fr/blog/6171/slugs-comment-les-generer/
*/
function slugify(string $str, string $sep = '-'): string
{
    // il vous faut l’extension `intl` installé et activée, sinon ça ne fonctionne pas
    if (!extension_loaded('intl')) {
        throw new \RuntimeException(
            'Missing Intl extension. This is required to use ' . __FUNCTION__ 
        );
    }


    return trim(
        preg_replace(
            '/[^a-z0-9]+/',
            $sep,
            \transliterator_transliterate(
                "Any-Latin; Latin-ASCII; Lower()",
                $str
            )
        ),
        $sep
    );
}

//echo slugify("Politique européenne d'aménagement");

define ('RACINE', 'https://registre.data.developpement-durable.gouv.fr/ecospheres/themes-ecospheres/');
$themes = file_get_contents('theme3.md');
$themes = explode("\n", $themes);

echo "<pre>\n";
//print_r($themes);

foreach ($themes as $theme) {
  if ((substr($theme, 0, 2)=='# ') || (substr($theme, 0, 5)=='#### ')) { // titre
    echo "$theme\n";
  }
  elseif (substr($theme, 0, 3)=='## ') { // theme de niveau 1 
    $theme = substr($theme, 3);
    echo "## [$theme](",RACINE,slugify($theme),")\n";
  }
  elseif (substr($theme, 0, 2)=='- ') { // theme de niveau 2
    $theme = substr($theme, 2);
    echo "- [$theme](",RACINE,slugify($theme),")\n";
  }
  elseif ($theme == '') {
    echo "\n";
  }
}
