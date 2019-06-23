<?php
/*PhpDoc:
name:  map.php
title: map.php - carte de démo des couches
includes: [ ]
doc: |
journal: |
  22/6/2019:
    - gestion des couches millésimées
  12/6/2019:
    - création
*/
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../llMap/llmap.inc.php';

use Symfony\Component\Yaml\Yaml;

$params = Yaml::parseFile(__DIR__.'/tiles.yaml');

if (!isset($_GET['dsid'])) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>map</title></head><body>\n";
  echo "<h2>Liste des jeux de données</h2><ul>\n";
  foreach ($params['datasets'] as $dsid => $dataset) {
    echo "<li><a href='?dsid=$dsid'>$dataset[title]<a>\n";
  }
  die("</ul>\n");
}

$dsid = $_GET['dsid'];
$dataset = $params['datasets'][$dsid];
$mapDef = $params['defaultMap'];
$mapDef['title'] = "carte $dsid";
$mapDef['bases'] = [];
$layers = [];
foreach($dataset['layersByGroup'] as $lyrgroup) {
  $layers = array_merge($layers, $lyrgroup);
}
//print_r($layers); die();

function lyrDef(string $dsid, array $dataset, string $lyrId, array $layer): array {
  $format = $layer['format'] ?? $dataset['format'];
  $fmt = $format=='image/png' ? 'png' : 'jpg';
  if ($_SERVER['HTTP_HOST']=='localhost') {
    $tileUrl = "http://localhost/geoapi/tiles/index.php/$dsid/$lyrId/{z}/{x}/{y}.$fmt";
    $docUrl = "http://localhost/geoapi/tiles/index.php/$dsid/$lyrId/html";
  }
  else {
    $tileUrl = "http://tiles.geoapi.fr/$dsid/$lyrId/{z}/{x}/{y}.$fmt";
    $docUrl = "http://tiles.geoapi.fr/$dsid/$lyrId/html";
  }
  $lyrDef = [
    'title'=> "<a href='$docUrl' target='_blank' title=\\\"".$layer['title']."\\\">$lyrId</a>",
    'type'=> 'TileLayer',
    'url'=> $tileUrl,
    'options'=> [
      'format'=> $layer['format'] ?? $dataset['format'],
      'minZoom'=> $layer['minZoom'] ?? $dataset['minZoom'],
      'maxZoom'=> $layer['maxZoom'] ?? $dataset['maxZoom'],
      'detectRetina'=> true,
      'attribution'=> $layer['attribution'] ?? ($dataset['attribution'] ?? 'IGN'),
    ],
  ];
  return $lyrDef;
}

foreach($layers as $lyrId => $layer) {
  if ($lyrId == 'error') continue;
  $format = $layer['format'] ?? $dataset['format'];
  $basoverl = $format=='image/jpeg' ? 'bases' : 'overlays';
  if (!isset($layer['years'])) {
    $mapDef[$basoverl][$lyrId] = lyrDef($dsid, $dataset, $lyrId, $layer);
  }
  else {
    foreach ($layer['years'] as $year) {
      $lyrIdYear = str_replace('{year}', $year, $lyrId);
      $layer['title'] = str_replace('{year}', $year, $layer['titleYear']);
      $mapDef[$basoverl][$lyrIdYear] = lyrDef($dsid, $dataset, $lyrIdYear, $layer);
    }
  }
}
$mapDef['bases']['cartes'] = [
  'title'=> "cartes",
  'type'=> 'TileLayer',
  'url'=> (($_SERVER['HTTP_HOST']=='localhost') ? 'http://localhost/geoapi/tiles/index.php' : 'http://tiles.geoapi.fr')
      .'/ignbase/cartes/{z}/{x}/{y}.jpg',
  'options'=>[ 'format'=>'image/jpeg', 'minZoom'=>0, 'maxZoom'=>18, 'detectRetina'=>true],
];
$mapDef['bases']['whiteimg'] = [
  'title'=> "Fond blanc",
  'type'=> 'TileLayer',
  'url'=> 'http://visu.gexplor.fr/utilityserver.php/whiteimg/{z}/{x}/{y}.jpg',
  'options'=>[ 'format'=>'image/jpeg', 'minZoom'=>0, 'maxZoom'=>21, 'detectRetina'=>true],
];

//echo "<pre>"; print_r($mapDef); die();

$map = new LLMap($mapDef);
echo $map->display();