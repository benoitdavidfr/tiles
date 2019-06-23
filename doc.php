<?php
// 2 objectifs:
// 1) lister les couches du WMTS absentes du web-service tiles
// 2) confronter des paramètres de tiles avec les capacités des flux IGN
require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>doc</title></head><body>\n";

if (!isset($_GET['action'])) {
  echo "<a href='?action=listWmtsLayers'>listWmtsLayers</a>\n";
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

// affiche une couche WMTS sous la forme d'une table HTML
function showWmtsLayer(SimpleXMLElement $layer) {
  echo "<h3>$layer->Title</h3>\n";
  echo "<table border=1>\n";
  echo "<tr><td>Identifier</td>",
       "<td>$layer->Identifier</td></tr>\n";
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

function showTileLayer(array $layer): string {
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

if ($_GET['action'] == 'listWmtsLayers') {
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  $tileLayers = [];
  foreach ($params['datasets'] as $dsid => $dataset) {
    foreach ($dataset['layersByGroup'] as $lyrGpe) {
      foreach ($lyrGpe as $lyrId => $layer) {
        if (isset($layer['gpname'])) {
          if (strpos($layer['gpname'], '{year}')) {
            foreach ($layer['years'] as $year) {
              $tileLayers[] = str_replace('{year}', $year, $layer['gpname']);
            }
          }
          else {
            //echo "$layer[gpname]<br>\n";
            $tileLayers[] = $layer['gpname'];
          }
        }
        else {
          foreach ($layer['source'] as $source) {
            //echo "$source[gpname]<br>\n";
            $tileLayers[] = $source['gpname'];
          }
        }
      }
    }
  }
  
  echo "<h2>Couches WMTS absentes de tiles</h2>\n";
  $wmtsUrl = $params['datasets']['ignbase']['distribution']['wmts']['url'];
  $wmtsCap = new WmtsCap($wmtsUrl);
  foreach ($wmtsCap->Contents->Layer as $layer) {
    if (in_array((string)$layer->Identifier, $tileLayers))
      continue;
    //showWmtsLayer($dsid, $layer);
    //echo "<pre>layer="; print_r($layer); echo "</pre>\n";
    echo "<a href='?action=showLayer&amp;id=$layer->Identifier'>$layer->Title</a> ($layer->Identifier)<br>\n";
  }
  die();
}

if ($_GET['action'] == 'showLayer') {
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  $wmtsUrl = $params['datasets']['ignbase']['distribution']['wmts']['url'];
  $wmtsCap = new WmtsCap($wmtsUrl);
  $layer = $wmtsCap->getLayerById($_GET['id']);
  showWmtsLayer($layer);
  echo "<pre>"; print_r($layer);
}