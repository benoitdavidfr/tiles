<?php
// confrontation des paramètres de tiles et des capacités des flux IGN
require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>doc</title></head><body>\n";
$params = Yaml::parseFile(__DIR__.'/tiles.yaml');

if (!isset($_GET['action'])) {
  foreach ($params['datasets'] as $dsid => $dataset) {
    echo "$dataset[title] : <a href='?action=showWmts&amp;dsid=$dsid'>showWmts</a>,
     <a href='?action=cmp&amp;dsid=$dsid'>cmp</a>,
     <a href='?action=diff&amp;dsid=$dsid'>diff</a><br>\n";
  }
  die();
}

class WmtsCap {
  private $url; // url : string
  private $cap; // cap : SimpleXMLElement
  
  function __construct(string $url) {
    $this->url = $url;
    $url .= "?SERVICE=WMTS&REQUEST=GetCapabilities";
    if (is_file(__DIR__.'/wmts_cap.xml')) {
      $cap = file_get_contents(__DIR__.'/wmts_cap.xml');
    }
    else {
      $cap = file_get_contents($url);
      file_put_contents(__DIR__.'/wmts_cap.xml', $cap);
    }
    //header("Content-Type: application/xml"); echo $cap; die();
    $cap = str_replace(['<ows:','</ows:'],['<','</'], $cap);

    $this->cap = new SimpleXMLElement($cap);
  }
  
  // récupère un champ des cap
  function __get(string $name) { return $this->cap->$name; }
  
  // retourne une layer définie par son identifiant ou null
  function getLayerById(string $lyrid): ?SimpleXMLElement {
    foreach ($this->cap->Contents->Layer as $layer) {
      if ($layer->Identifier == $lyrid) {
        return $layer;
      }
    }
    return null;
  }
};

// extrait du TileMatrixSetLink de la couche le TileMatrixSet et l'intervalle de niveaux de zoom
function zooms(SimpleXMLElement $TileMatrixSetLink): string {
  //echo "<pre>TileMatrixSetLink= "; print_r($TileMatrixSetLink); echo "</pre>\n";
  //echo "<pre>TileMatrixSetLink->TileMatrixSetLimits= "; print_r($TileMatrixSetLink->TileMatrixLimits); echo "</pre>\n";
  $minZoom = null;
  $maxZoom = null;
  foreach ($TileMatrixSetLink->TileMatrixSetLimits->TileMatrixLimits as $TileMatrixLimit) {
    //echo "<pre>TileMatrixLimit= "; print_r($TileMatrixLimit); echo "</pre>\n";
    $zoom = (int)$TileMatrixLimit->TileMatrix;
    if (($minZoom === null) || ($zoom < $minZoom)) $minZoom = $zoom;
    if (($maxZoom === null) || ($zoom > $maxZoom)) $maxZoom = $zoom;
    //echo "minZoom=$minZoom, maxZoom=$maxZoom<br>\n";
  }
  return $TileMatrixSetLink->TileMatrixSet." $minZoom $maxZoom";
}

// affiche une couche sous la forme d'une table HTML
function showWmtsLayer(string $dsid, SimpleXMLElement $layer) {
  echo "<h3>$layer->Title</h3>\n";
  echo "<table border=1>\n";
  echo "<tr><td>Identifier</td>",
       "<td><a href='?action=$_GET[action]&amp;dsid=$dsid&amp;layer=$layer->Identifier'>$layer->Identifier</a></td></tr>\n";
  echo "<tr><td>Abstract</td><td>$layer->Abstract</td></tr>\n";
  echo "<tr><td>Format</td><td>$layer->Format</td></tr>\n";
  echo "<tr><td>WGS84BoundingBox</td><td>LowerCorner:",$layer->WGS84BoundingBox->LowerCorner,
       ", UpperCorner:",$layer->WGS84BoundingBox->UpperCorner,"</td></tr>\n";
  echo "<tr><td>styles</td><td><table border=1>";
  foreach ($layer->Style as $style) {
    echo "<tr><td>$style->Identifier</td><td>$style->Title</td><td>$style->Abstract</td></tr>";
  }
  echo "</table></td></tr>\n";
  echo "<tr><td>zooms</td><td>",zooms($layer->TileMatrixSetLink),"</td></tr>\n";
  echo "</table>\n";
}

$dsid = $_GET['dsid'];
$wmts = $params['datasets'][$dsid]['distribution']['wmts'];
$wmtsCap = new WmtsCap($wmts['url']);

// Listage des couches du serveur WMTS avec détail par couche 
if ($_GET['action'] == 'showWmts') {
  if (!isset($_GET['layer'])) {
    echo "<h2>Layers</h2>\n";
    foreach ($wmtsCap->Contents->Layer as $layer) {
      showWmtsLayer($dsid, $layer);
      //echo "<pre>layer="; print_r($layer); echo "</pre>\n";
    }
  }
  else {
    $layer = $wmtsCap->getLayerById($_GET['layer']);
    showWmtsLayer($dsid, $layer);
    echo "<pre>layer="; print_r($layer); echo "</pre>\n";
  }
  die();
}

function showLayer(array $layer): string {
  $html = "<h3>$layer[title]</h3>"
    ."<table border=1>"
    ."<tr><td>abstract</td><td>$layer[abstract]</td></tr>"
    ."<tr><td>source</td><td>"
      .(!isset($layer['source']) ? ''
        : (is_array($layer['source']) ?
          '<ul><li>'.implode('</li><li>',$layer['source']).'</li></ul>'
            : $layer['source']))
    ."</td></tr>"
    ."<tr><td>format</td><td>$layer[format]</td></tr>"
    ."<tr><td>zooms</td><td>$layer[minZoom] - $layer[maxZoom]</td></tr>"
    .(isset($layer['attribution']) ? "<tr><td>attribution</td><td>$layer[attribution]</td></tr>" : '')
    .(isset($layer['protocol']) ? "<tr><td>protocol</td><td>$layer[protocol]</td></tr>" : '')
    ."<tr><td>gpname(s)</td><td>".($layer['gpname'] ?? implode(', ',$layer['gpnames']))."</td></tr>"
    .(isset($layer['style']) ? "<tr><td>style</td><td>$layer[style]</td></tr>" : '')
    ."</table>";
  return $html.Yaml::dump($layer);
}

// comparaison entre les couches définies dans params et celles définies dans les capacités du serveur WMTS
if ($_GET['action'] == 'cmp') {
  foreach ($params['datasets'][$_GET['dsid']]['layersByGroup'] as $lyrGroup) {
    foreach ($lyrGroup as $lyrid => $layer) {
      echo "<h3>$layer[title]</h3>\n";
      echo "<table border=1><tr>";
      echo "<td>",showLayer($layer),"</td>";
      if (isset($layer['gpname'])) {
        if ($wmtsLayer = $wmtsCap->getLayerById($layer['gpname'])) {
          echo "<td>"; showWmtsLayer($dsid, $wmtsLayer); echo "</td>";
        }
        else
          echo "<td>Not found</td>";
      }
      echo "</tr></table>\n";
    }
  }
  die();
}

// couches du serveur WMTS non définies dans params
if ($_GET['action'] == 'diff') {
  if (!isset($_GET['layer'])) {
    echo "<h2>Couches du serveur WMTS absentes du sevice $_GET[dsid]</h2>\n";
    $gpnames = [];
    foreach ($params['datasets'][$_GET['dsid']]['layersByGroup'] as $lyrGroup) {
      foreach ($lyrGroup as $lyrid => $layer) {
        if (isset($layer['gpname']) && !isset($layer['protocol']))
          $gpnames[$layer['gpname']] = 1;
      }
    }
    foreach ($wmtsCap->Contents->Layer as $layer) {
      //echo "Identifier=",$layer->Identifier,"<br>\n";
      if (!isset($gpnames[(string)$layer->Identifier]))
        showWmtsLayer($dsid, $layer);
      //echo "<pre>layer="; print_r($layer); echo "</pre>\n";
    }
  }
  else {
    $layer = $wmtsCap->getLayerById($_GET['layer']);
    showWmtsLayer($dsid, $layer);
    echo "<pre>layer="; print_r($layer); echo "</pre>\n";
  }
  die();
}
