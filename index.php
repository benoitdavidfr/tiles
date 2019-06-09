<?php
/*PhpDoc:
name:  index.php
title: index.php - service de tuiles simplifiant l'accès aux ressources notamment du GP IGN
includes: [ layers.inc.php, genmap.inc.php, getkey.inc.php ]
doc: |
  Service de tuiles au std OSM simplifiant l'accès au WMTS du GP IGN
  Fonctionnalités:
    - appel sans clé
    - simplification des paramètres / WMTS
    - simplification des noms de couches
    - ajout de couches non disponibles en WMTS
    - documentation intégrée
    - couche cartes plus simple d'emploi
    - mise en cache pour 21 jours (la durée pourrait dépendre du zoom)
    
  Gestion des erreurs:
  - seules les erreurs de logique du code génèrent un die()
  - en fonctionnement normal toutes les erreurs génèrent une erreur HTTP
journal: |
  9/6/2019:
    - ajout /api
  8/6/2019:
    - fork de /geoapi/igngp/tile.php
*/
require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$version = '2019-06-08T21:00:00';
$path_info = $_SERVER['PATH_INFO'] ?? null;
$script_path = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";

$mimetypes = [
  'jpg' => 'image/jpeg',
  'png' => 'image/png',
  'html' => 'text/html',
];

function error(int $code, array $message) {
  $headers = [
    400 => "Bad Request",
    404 => "Not Found",
    500 => "Internal Server Error",
    501 => "Not Implemented",
  ];
  header(sprintf('HTTP/1.1 %d %s', $code, $headers[$code] ?? 'header not defined'));
  die(json_encode($message, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// racine = titre + liste des jeux de données + exemples d'appels
if (!$path_info || ($path_info == '/')) {
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  $datasets = [];
  foreach ($params['datasets'] as $dsid => $dataset) {
    $datasets[$dsid] = [
      'title'=> $dataset['title'],
      'abstract'=> $dataset['abstract'],
      'licence'=> $dataset['licence'],
      'spatial'=> $dataset['spatial'],
      'href'=> "$script_path/$dsid",
    ];
  }
  $examples = [];
  foreach ($params['examples'] as $example) {
    $examples[] = [
      'title'=> $example['title'],
      'href'=> "$script_path$example[href]",
    ];
  }
  header('Content-type: application/json');
  die(json_encode([
    'title'=> "Liste des jeux de données exposés",
    'self'=> $script_path,
    'version'=> $version,
    'api'=> ['title'=> "définition de l'API", 'href'=> "$script_path/api"],
    'datasets'=> $datasets,
    'examples'=> $examples,
  ]));
}

if ($path_info == '/api') {
  $api = Yaml::parseFile(__DIR__.'/api.yaml');
  header('Content-type: application/json');
  die(json_encode($api));
}

// "/{dsid}" - jeu de données
if (preg_match('!^/([^/]*)$!', $path_info, $matches)) {
  $dsid = $matches[1];
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  
  if (!isset($params['datasets'][$dsid]))
    error(404, ['error'=> "Erreur $script_path/$path_info ne correspond pas à un jeu de données"]);
  
  $dataset = $params['datasets'][$dsid];
  header('Content-type: application/json');
  die(json_encode([
    'title'=> $dataset['title'],
    'abstract'=> $dataset['abstract'],
    'licence'=> $dataset['licence'],
    'spatial'=> $dataset['spatial'],
    'self'=> "$script_path/$dsid",
    'layers'=> ['title'=> "liste des couches", 'href'=> "$script_path/$dsid/layers"],
  ]));
}

// "/{dsid}/layers" - liste des couches
if (preg_match('!^/([^/]*)/layers$!', $path_info, $matches)) {
  $dsid = $matches[1];
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  
  if (!isset($params['datasets'][$dsid]))
    error(404, ['error'=> "Erreur $script_path/$dsid ne correspond pas à un jeu de données"]);
  
  $layers = $params['datasets'][$dsid]['layers'];
  $result = [];
  foreach ($layers as $lyrId => $layer) {
    $result['layers'][$lyrId] = [
      'title'=> $layer['title'],
      'href'=> "$script_path$path_info/$lyrId",
    ];
  }
  header('Content-type: application/json');
  die(json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// "/{dsid}/layers/{lyrId}" - une couche
if (preg_match('!^/([^/]*)/layers/([^/]*)$!', $path_info, $matches)) {
  $dsid = $matches[1];
  $lyrId = $matches[2];
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  
  if (!isset($params['datasets'][$dsid]))
    error(404, ['error'=> "Erreur $script_path/$dsid ne correspond pas à un jeu de données"]);
  
  $layers = $params['datasets'][$dsid]['layers'];
  if (!isset($layers[$lyrId]))
    error(404, ['error'=> "Erreur $script_path/$path_info ne correspond pas à une couche"]);
  
  $layer = $layers[$lyrId];
  header('Content-type: application/json');
  die(json_encode([
    'title'=> $layer['title'],
    'self'=> "$script_path$path_info/$lyrId",
    'abstract'=> $layer['abstract'],
    'format'=> $layer['format'],
    'minZoom'=> $layer['minZoom'],
    'maxZoom'=> $layer['maxZoom'],
  ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// "/{dsid}/layers/{lyrId}/{zoom}/{x}/{y}.{fmt}" - une tuile
if (!preg_match('!^/([^/]*)/layers/([^/]*)/(\d*)/(\d*)/(\d*)\.(jpg|png|html)$!', $path_info, $matches))
  error(404, ['error'=> "Erreur $script_path$path_info ne correspond pas à un point d'entrée"]);

$dsid = $matches[1];
$lyrId = $matches[2];
$zoom = $matches[3];
$x = $matches[4];
$y = $matches[5];
$format = $mimetypes[$matches[6]] ?? null;

$params = Yaml::parseFile(__DIR__.'/tiles.yaml');

if (!isset($params['datasets'][$dsid]))
  error(404, ['error'=> "Erreur $script_path/$dsid ne correspond pas à un jeu de données"]);

$layers = $params['datasets'][$dsid]['layers'];
if (!isset($layers[$lyrId]))
  error(404, ['error'=> "Erreur $script_path/$path_info ne correspond pas à une couche"]);
$layer = $layers[$lyrId];

function imgpath(string $path0, int $zoom, int $x, int $y, string $fmt): string {
  return sprintf("%s/%d/%d/%d.%s", $path0, $zoom, $x, $y, $fmt);
}

function cell(string $path0, int $zoom, int $x, int $y, string $fmt) {
  return "<td><a href='".imgpath($path0, $zoom, $x, $y, 'html')."'>"
        ."<img src='".imgpath($path0, $zoom, $x, $y, $fmt)."'></a></td>\n";
}

if ($format == 'text/html') {
  $path0 = "$script_path/$dsid/layers/$lyrId";
  if ($zoom > 1)
    echo "<a href='",imgpath($path0, $zoom-1, intdiv($x, 2), intdiv($y, 2), 'html'),"'>zoom-out</a><br>\n";
  echo "<table><tr>\n";
  if ($y > 1) {
    echo cell($path0, $zoom, $x-1, $y-1, 'jpg');
    echo cell($path0, $zoom, $x, $y-1, 'jpg');
    echo cell($path0, $zoom, $x+1, $y-1, 'jpg');
    echo "</tr><tr>\n";
  }
  echo cell($path0, $zoom, $x-1, $y, 'jpg');
  echo "<td><a href='",imgpath($path0, $zoom+1, 2*$x+1, 2*$y+1, 'html'),"'>",
    "<img src='",imgpath($path0, $zoom, $x, $y, 'jpg'),"'></a></td>\n";
  echo cell($path0, $zoom, $x+1, $y, 'jpg');
  echo "</tr><tr>\n";
  echo cell($path0, $zoom, $x-1, $y+1, 'jpg');
  echo cell($path0, $zoom, $x, $y+1, 'jpg');
  echo cell($path0, $zoom, $x+1, $y+1, 'jpg');
  echo "</tr></table>\n";
  die();
}

$gpname2 = null;
if (isset($layer['gpname']))
  $gpname = $layer['gpname'];
else {
  $gpname = $layer['gpnames'][0];
  $gpname2 = $layer['gpnames'][1];
}
//print_r($matches);

// calcul du BBox à partir de (z,x,y)
function bbox(int $zoom, int $ix, int $iy): array {
  $base = 20037508.3427892476320267;
  $size0 = $base * 2;
  $x0 = - $base;
  $y0 =   $base;
  $size = $size0 / pow(2, $zoom);
  return [
    $x0 + $size * $ix,
    $y0 - $size * ($iy+1),
    $x0 + $size * ($ix+1),
    $y0 - $size * $iy,
  ];
}

$distribution = $params['datasets'][$dsid]['distribution'];
if (!isset($layer['protocol'])) { // par défaut protocole WMTS
  $style = $layer['style'] ?? 'normal';
  $url = $distribution['wmts']['url'].'?'
        .'service=WMTS&version=1.0.0&request=GetTile'
        .'&tilematrixSet=PM&height=256&width=256'
        ."&layer=$gpname&format=$format&style=$style"
        ."&tilematrix=$zoom&tilecol=$x&tilerow=$y";
  if ($gpname2)
    $url2 = $params['datasets'][$dsid]['distribution']['wmts']['url'].'?'
          .'service=WMTS&version=1.0.0&request=GetTile'
          .'&tilematrixSet=PM&height=256&width=256'
          ."&layer=$gpname2&format=$format&style=$style"
          ."&tilematrix=$zoom&tilecol=$x&tilerow=$y";
  $referer = $distribution['wmts']['referer'];
}
elseif ($layers[$lyrId]['protocol']=='WMS') { // sauf si explicitement WMS
  $style = $layers[$lyrId]['style'] ?? '';
  $url = $distribution['wms']['url'].'?'
        .'service=WMS&version=1.3.0&request=GetMap'
        ."&layers=$gpname&format=".urlencode($format)."&styles=$style"
        .($format=='image/png' ? '&transparent=true' : '')
        .'&crs='.urlencode('EPSG:3857').'&bbox='.implode(',',bbox($zoom,$x,$y))
        .'&height=256&width=256';
  $referer = $distribution['wms']['referer'];
  //  die("url=<a href='$url'>$url</a>\n");
} else
  error(500, "protocole $layer[protocol] inconnu");

// Envoi des données avec mise en cache
function sendData($format, $data) {
  $nbDaysInCache = 21;
  header('Cache-Control: max-age='.($nbDaysInCache*24*60*60)); // mise en cache pour $nbDaysInCache jours
  header('Expires: '.date('r', time() + ($nbDaysInCache*24*60*60))); // mise en cache pour $nbDaysInCache jours
  header('Last-Modified: '.date('r'));
  header("Content-Type: $format");
  die($data);
}

$http_context_options = [
  'method'=>"GET",
  'timeout' => 10, // 10'
  'header'=>"Accept-language: en\r\n"
           ."referer: $referer\r\n",
];
$stream_context = stream_context_create(['http'=>$http_context_options]);
if (($data = @file_get_contents($url, false, $stream_context)) !== FALSE)
  sendData($format, $data);
  
if ((substr($http_response_header[0], 9, 3)=='404') && $gpname2)
  if (($data = @file_get_contents($url2, false, $stream_context)) !== FALSE)
    sendData($format, $data);
  
$errorCode = substr($http_response_header[0], 9, 3);
$errorMessages = [
  400 => 'HTTP/1.1 400 Bad request',
  403 => 'HTTP/1.1 403 Forbidden',
  404 => 'HTTP/1.1 404 Not Found',
];
$errorMessage = $errorMessages[$errorCode] ?? "HTTP/1.1 $errorCode Error";
error($errorCode, ['error'=> $errorMessage]);
