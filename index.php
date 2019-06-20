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
  A FAIRE:
    - pourquoi prendre le format passé en paramètre et pas celui défini pour la couche ???
journal: |
  12/6/2019:
    - possibilité d'appel "/{dsid}/{lyrId}"
    - ajout doc d'une couche en html "/{dsid}/{lyrId}/html"
  10/6/2019:
    - suppression du point /layers
    - suppression de layers dans le path
    - utilisation comme URI d'une couche du pattern des images
    - amélioration du viewer Html
  9/6/2019:
    - ajout /api
  8/6/2019:
    - fork de /geoapi/igngp/tile.php
*/
require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$version = '2019-06-12T08:30:00';
$path_info = $_SERVER['PATH_INFO'] ?? null;
$script_path = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";

$mimetypes = [
  'jpg' => 'image/jpeg',
  'png' => 'image/png',
  'html' => 'text/html',
];

// renvoie un message d'erreur en JSON avec un code d'erreur HTTP
function error(int $code, array $message) {
  $headers = [
    400 => "Bad Request",
    403 => 'Forbidden',
    404 => "Not Found",
    500 => "Internal Server Error",
    501 => "Not Implemented",
  ];
  header(sprintf('HTTP/1.1 %d %s', $code, $headers[$code] ?? 'header not defined'));
  header('Content-Type: application/json');
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
  $layers = [];
  foreach ($dataset['layersByGroup'] as $lyrGroup) {
    foreach ($lyrGroup as $lyrId => $layer) {
      $layers[$lyrId] = [
        'title'=> $layer['title'],
        'href'=> "$script_path$path_info/$lyrId/{z}/{x}/{y}.".($layer['format']=='image/png' ? 'png' : 'jpg'),
      ];
    }
  }

  header('Content-type: application/json');
  die(json_encode([
    'title'=> $dataset['title'],
    'self'=> "$script_path/$dsid",
    'abstract'=> $dataset['abstract'],
    'licence'=> $dataset['licence'],
    'spatial'=> $dataset['spatial'],
    'layers'=> $layers,
  ]));
}

// "/{dsid}/{lyrId}/z/x/y.{fmt}" ou "/{dsid}/{lyrId}"- une couche
if (preg_match('!^/([^/]*)/([^/]*)(/{z}/{x}/{y}\.(jpg|png))?$!', $path_info, $matches)) {
  $dsid = $matches[1];
  $lyrId = $matches[2];
  $fmt = isset($matches[3]) ? $matches[4] : null;
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  
  if (!isset($params['datasets'][$dsid]))
    error(404, ['error'=> "Erreur $script_path/$dsid ne correspond pas à un jeu de données"]);
  
  $dataset = $params['datasets'][$dsid];
  $layers = [];
  foreach ($dataset['layersByGroup'] as $lyrGroup)
    $layers = array_merge($layers, $lyrGroup);
  if (!isset($layers[$lyrId]))
    error(404, ['error'=> "Erreur $script_path$path_info ne correspond pas à une couche"]);
  
  $layer = $layers[$lyrId];
  $lyrFmt = ($layer['format']=='image/png' ? 'png' : 'jpg');
  if ($fmt && ($lyrFmt <> $fmt))
    error(404, ['error'=> "Erreur sur $script_path$path_info, format $fmt incorrect"]);
  
  header('Content-type: application/json');
  die(json_encode([
    'title'=> $layer['title'],
    'self'=> "$script_path$path_info",
    'abstract'=> $layer['abstract'],
    'format'=> $layer['format'],
    'minZoom'=> $layer['minZoom'],
    'maxZoom'=> $layer['maxZoom'],
    'tileUrlPattern'=> "$script_path/$dsid/$lyrId/{z}/{x}/{y}.$lyrFmt",
  ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// "/{dsid}/{lyrId}/html"- doc de la couche en HTML
if (preg_match('!^/([^/]*)/([^/]*)/html$!', $path_info, $matches)) {
  $dsid = $matches[1];
  $lyrId = $matches[2];
  $fmt = isset($matches[3]) ? $matches[4] : null;
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  
  if (!isset($params['datasets'][$dsid])) {
    header('HTTP/1.1 404 Not Found');
    echo "Erreur $script_path/$dsid ne correspond pas à un jeu de données<br>\n";
    die();
  }  
  $dataset = $params['datasets'][$dsid];
  $layers = [];
  foreach ($dataset['layersByGroup'] as $lyrGroup)
    $layers = array_merge($layers, $lyrGroup);
  if (!isset($layers[$lyrId])) {
    header('HTTP/1.1 404 Not Found');
    echo "Erreur $script_path/$dsid/$lyrId ne correspond pas à une couche<br>\n";
    die();
  }
  $layer = $layers[$lyrId];
  $lyrFmt = ($layer['format']=='image/png' ? 'png' : 'jpg');
  
  echo "<h3>$layer[title] ($lyrId)</h3>\n",
    "<table border=1>",
    "<tr><td><i>title</i></td><td>$layer[title]</td></tr>",
    "<tr><td><i>abstract</i></td><td>$layer[abstract]</td></tr>",
    "<tr><td><i>format</i></td><td>$layer[format]</td></tr>",
    "<tr><td><i>minZoom</i></td><td>$layer[minZoom]</td></tr>",
    "<tr><td><i>maxZoom</i></td><td>$layer[maxZoom]</td></tr>",
    "<tr><td><i>tileUrlPattern</i></td><td>$script_path/$dsid/$lyrId/{z}/{x}/{y}.$lyrFmt</td></tr>",
    "</table>\n";
  die();
}

// "/{dsid}/layers/{lyrId}/{zoom}/{x}/{y}.{fmt}" - une tuile ou une page
if (!preg_match('!^/([^/]*)/([^/]*)/(\d*)/(\d*)/(\d*)\.(jpg|png|html)$!', $path_info, $matches))
  error(404, ['error'=> "Erreur $script_path$path_info ne correspond pas à un point d'entrée"]);

$dsid = $matches[1];
$lyrId = $matches[2];
$zoom = $matches[3];
$x = $matches[4];
$y = $matches[5];
$format = $mimetypes[$matches[6]] ?? null;

if (isset($_GET['layer'])) {
  $lyrId = $_GET['layer'];
}
$params = Yaml::parseFile(__DIR__.'/tiles.yaml');

if (!($dataset = $params['datasets'][$dsid] ?? null))
  error(404, ['error'=> "Erreur $script_path/$dsid ne correspond pas à un jeu de données"]);

$layers = [];
foreach ($dataset['layersByGroup'] as $lyrGroup)
  $layers = array_merge($layers, $lyrGroup);
if (!isset($layers[$lyrId]))
  error(404, ['error'=> "Erreur $script_path/$path_info ne correspond pas à une couche"]);
$layer = $layers[$lyrId];

if ($format == 'text/html') {
  require_once __DIR__.'/htmlviewer.inc.php';
  htmlViewer("$script_path/$dsid/$lyrId", $dataset['layersByGroup'], $layers, $lyrId, $zoom, $x, $y);
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
        ."&layer=$gpname&format=$format&style=".urlencode($style)
        ."&tilematrix=$zoom&tilecol=$x&tilerow=$y";
  if ($gpname2)
    $url2 = $params['datasets'][$dsid]['distribution']['wmts']['url'].'?'
          .'service=WMTS&version=1.0.0&request=GetTile'
          .'&tilematrixSet=PM&height=256&width=256'
          ."&layer=$gpname2&format=$format&style=".urlencode($style)
          ."&tilematrix=$zoom&tilecol=$x&tilerow=$y";
  $referer = $distribution['wmts']['referer'];
}
elseif ($layers[$lyrId]['protocol']=='WMS') { // sauf si explicitement WMS
  $style = $layers[$lyrId]['style'] ?? '';
  $url = $distribution['wms']['url'].'?'
        .'service=WMS&version=1.3.0&request=GetMap'
        ."&layers=$gpname&format=".urlencode($format)."&styles=".urlencode($style)
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
  //$nbDaysInCache = 1/24/60; // 1'
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
else
  $urlError = $url;
  
if (isset($http_response_header[0]) && (substr($http_response_header[0], 9, 3)=='404') && $gpname2) {
  if (($data = @file_get_contents($url2, false, $stream_context)) !== FALSE)
    sendData($format, $data);
  else
    $urlError = $url2;
}

if (!isset($http_response_header[0])) {
  $errorCode = 500;
  $errorMessage = "erreur inconnue";
}
else {
  $errorCode = substr($http_response_header[0], 9, 3);
  $errorMessages = [
    400 => 'HTTP/1.1 400 Bad request',
    403 => 'HTTP/1.1 403 Forbidden',
    404 => 'HTTP/1.1 404 Not Found',
  ];
  $errorMessage = $errorMessages[$errorCode] ?? "HTTP/1.1 $errorCode Error";
}
error($errorCode, ['error'=> "$errorMessage on $urlError"]);
