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
    - génération de carte Leaflet intégrée
    - couche cartes plus simple d'emploi
    - mise en cache pour 21 jours (la durée pourrait dépendre du zoom)
    
  Gestion des erreurs:
  - seules les erreurs de logique du code génèrent un die()
  - en fonctionnement normal toutes les erreurs génèrent une erreur HTTP
journal: |
  8/6/2019:
    - fork de /geoapi/igngp/tile.php
*/
require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$version = '2019-06-08T18:30:00';
$path_info = $_SERVER['PATH_INFO'] ?? null;
$script_path = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";

//echo "<pre>"; print_r($_SERVER); echo "</pre>\n";

// Affiche la définition des niveaux de zoom
if (isset($_GET['action']) && ($_GET['action']=='pixelsize')) {
  echo "<html><head><meta charset='UTF-8'><title>tile</title></head><body>",
       "<h2>Définition des niveaux de zoom</h2>\n",
      "<table border=1>",
      "<th>niveau</th><th>résolution (m)</th><th>échelle</th><th>dalle (km)</th>\n";
// correspond à 2 * PI * a / a = 6 378 137.0 est le demi-axe majeur de l'ellipsoide WGS 84
  $size0 = 20037508.3427892476320267 * 2; // circonférence de la Terre en mètres
  for ($i=0; $i<=21; $i++)
    echo "<tr>",
         "<td align=right>$i</td>",
         "<td align=right>",sprintf('%.2f',$size0/256/pow(2,$i)),"</td>",
         "<td>1/",round($size0/256/pow(2,$i)/0.00028),"</td>",
         "<td align=right>",sprintf('%.3f',$size0/1000/pow(2,$i)),"</td>",
         "</tr>\n";  
  echo "</table>\n";
  die();
}

// Affiche le résumé associé à une ressource
if (isset($_GET['action']) && ($_GET['action']=='abstract')) {
  $lyrname = $_GET['layer'];
  $layer = $layers[$lyrname];
  echo "<html><head><meta charset='UTF-8'><title>tile</title></head><body>",
       "<h2>Résumé de la couche \"$layer[title]\"</h2>\n",
       "$layer[abstract]</p>\n";
  die();
}

// Construit l'élément de doc correspondant à une couche
function layerdoc($layer, $lyrname) {
  $layerdoc = [
    'name'=> $lyrname,
    'title'=> $layer['title'],
    'url'=> "http://igngp.geoapi.fr/tile.php/$lyrname",
    'minZoom'=> $layer['minZoom'],
    'maxZoom'=> $layer['maxZoom'],
    'format'=> ($layer['format']=='jpg' ? 'image/jpeg' : 'image/png'),
//      'xx'=>$layer,
  ];
  if (isset($layer['doc']) and is_string($layer['doc']))
    $layerdoc['doc_url'] = $layer['doc'];
  elseif (isset($layer['doc']))
    foreach ($layer['doc'][''] as $minZoom => $slyrdoc)
      $layerdoc['doc_urls'][] = [
        'minZoom' => $minZoom,
        'maxZoom' => $slyrdoc['max'],
        'title'=>$slyrdoc['title'],
        'doc_url'=>$slyrdoc['doc'],
//          'xx'=>$slyrdoc,
      ];
  if (isset($layer['abstract']))
    $layerdoc['abstract'] = $layer['abstract'];
  return $layerdoc;
}

// Affiche la doc d'une layer en JSON
function layerdocinjason($layers, $lyrname, $z=null, $x=null) {
  header("Content-Type: text/plain; charset=utf-8");
  if (!isset($layers[$lyrname])) {
    header('HTTP/1.1 404 Not Found');
    die(
      json_encode(
        ['error_message'=>"layer $lyrname inconnu"],
        JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  }
  $doc = layerdoc($layers[$lyrname], $lyrname);
  if ($z===null)
    die(json_encode($doc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
//  require_once 'getlimits.inc.php';
//  $doc['limits'] = getLimits($layers[$lyrname]);
  die(json_encode($doc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// Affiche la doc en JSON
function docinjson($layers, $message=null) {
  header("Content-Type: text/plain; charset=utf-8");
  $doc = [
    'title'=> "Serveur de tuiles des ressources de l'IGN",
    'abstract' => "Ce service propose un accès simplifié aux tuiles exposées sur l'infrastructure IGN du Géoportail."
      ." Plus d'informations sur <a href='http://igngp.geoapi.fr/'>http://igngp.geoapi.fr/</a>.",
    'contact'=> 'contact@geoapi.fr',
    'doc_url'=> 'http://igngp.geoapi.fr/tile.php',
    'api_version'=> '2017-02-07T09:00',
    'end_points'=> [
      'tile.php' => ['GET'=> "documentation de l'API"],
      'tile.php/{layer}' => ['GET'=> "documentation de la couche {layer}"],
      'tile.php/{layer}/{z}/{x}/{y}.[png|jpg]' => ['GET'=> "image zoom {z} colonne {x} ligne {y} de la couche {layer} en format png ou en jpg"],
    ],
    'layers' => [],
  ];
  foreach ($layers as $lyrname => $layer)
    $doc['layers'][] = layerdoc($layer, $lyrname);
  die(json_encode($doc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// Affiche la doc en HTML
function doc($layers, $message=null) {
  if (!isset($_SERVER['HTTP_ACCEPT']) or ($_SERVER['HTTP_ACCEPT']=='application/json'))
    docinjson($layers, $message);
  echo "<html><head><meta charset='UTF-8'><title>tile</title></head><body>",
       "<h2>Serveur de tuiles des ressources de l'IGN</h2>\n",
       ($message ? "$message<br>\n" : ''),
       "format d'appel: <code>http://$_SERVER[SERVER_NAME]$_SERVER[SCRIPT_NAME]/{layer}/{z}/{x}/{y}.[jpg|png]</code><br>\n",
       "Où {layer} est le nom d'une des couches ci-dessous.<br>\n",
       "Les couches peuvent être co-visualisées en les sélectionnant avec le bouton radio de droite ",
       "soit en couche de base soit en couche superposée (overlay).<br>\n",
       "Cette co-visualisation fournit par la même occasion un exemple simple de carte Leaflet utilisant les couches.<br>\n",
       "Consulter les <a href='index.html#cu'>conditions d'utilisation</a><br>\n",
       "<form><table border=1><th>nom</th><th>titre</th><th>off/base/overlay</th>\n",
       "<input type='hidden' name='action' value='map'/>\n";
  foreach ($layers as $lyrname => $layer) {
    $href = (isset($layer['doc']) ?
              (is_string($layer['doc']) ? $layer['doc'] : "?action=doc&amp;layer=$lyrname")
            : (isset($layer['abstract']) ?
                "?action=abstract&amp;layer=$lyrname"
              : null));
    echo "<tr><td>$lyrname</td>",
         "<td>",($href ? "<a href='$href' target='_blank'>$layer[title]</a>" : $layer['title']),"</td>",
         "<td><input type='radio' name='$lyrname' value='off' checked> ",
         "<input type='radio' name='$lyrname' value='base'>  ",
         "<input type='radio' name='$lyrname' value='overlay'></td></tr>\n";
  }
  echo "<tr><td colspan=3><center><input type='submit' value='carte'></center></td></tr>\n",
       "</table></form>\n",
       "<a href='?action=docinjson'>Affichage de la doc en JSON</a>\n";
  die();
}

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

// racine = titre + liste des points d'entrée + exemples d'appels
if (!$path_info || ($path_info == '/')) {
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  $entryPoints = [];
  foreach ($params['entryPoints'] as $id => $entryPoint) {
    $entryPoints[$id] = [
      'title'=> $entryPoint['title'],
      'abstract'=> $entryPoint['abstract'],
      'licence'=> $entryPoint['licence'],
      'spatial'=> $entryPoint['spatial'],
      'href'=> "$script_path/$id",
    ];
  }
  $examples = [];
  foreach ($params['examples'] as $id => $example) {
    $examples[] = [
      'title'=> $example['title'],
      'href'=> "$script_path$example[href]",
    ];
  }
  header('Content-type: application/json');
  die(json_encode([
    'title'=> "Liste des données exposées par ce bouquet",
    'self'=> $script_path,
    'version'=> $version,
    'entryPoints'=> $entryPoints,
    'examples'=> $examples,
  ]));
}

// "/{entryPoint}" - correspond à un point d'entrée
if (preg_match('!^/([^/]*)$!', $path_info, $matches)) {
  $entryPointId = $matches[1];
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  
  if (!isset($params['entryPoints'][$entryPointId]))
    error(404, ['error'=> "Erreur $script_path/$path_info ne correspond pas à un point d'entrée"]);
  
  $entryPoint = $params['entryPoints'][$entryPointId];
  header('Content-type: application/json');
  die(json_encode([
    'title'=> $entryPoint['title'],
    'abstract'=> $entryPoint['abstract'],
    'licence'=> $entryPoint['licence'],
    'spatial'=> $entryPoint['spatial'],
    'href'=> "$script_path/$entryPointId",
    'self'=> "$script_path/$entryPointId",
    'api'=> ['title'=> "documentation de l'API", 'href'=> "$script_path/$entryPointId/api"],
    'layers'=> ['title'=> "liste des couches", 'href'=> "$script_path/$entryPointId/layers"],
  ]));
}

// "/{entryPoint}/layers" - liste des couches
if (preg_match('!^/([^/]*)/layers$!', $path_info, $matches)) {
  $entryPointId = $matches[1];
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  
  if (!isset($params['entryPoints'][$entryPointId]))
    error(404, ['error'=> "Erreur $script_path/$entryPointId ne correspond pas à un point d'entrée"]);
  
  $layers = $params['entryPoints'][$entryPointId]['layers'];
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

// "/{entryPoint}/layers/{lyrId}" - correspond à une couche
if (preg_match('!^/([^/]*)/layers/([^/]*)$!', $path_info, $matches)) {
  $entryPointId = $matches[1];
  $lyrId = $matches[2];
  $params = Yaml::parseFile(__DIR__.'/tiles.yaml');
  
  if (!isset($params['entryPoints'][$entryPointId]))
    error(404, ['error'=> "Erreur $script_path/$entryPointId ne correspond pas à un point d'entrée"]);
  
  $layers = $params['entryPoints'][$entryPointId]['layers'];
  if (!isset($layers[$lyrId]))
    error(404, ['error'=> "Erreur $script_path/$path_info ne correspond pas à une couche"]);
  
  $layer = $params['entryPoints'][$entryPointId]['layers'][$lyrId];
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

if (!preg_match('!^/([^/]*)/layers/([^/]*)/(\d*)/(\d*)/(\d*)\.(jpg|png|html)$!', $path_info, $matches))
  error(404, ['error'=> "Erreur $script_path$path_info ne correspond pas à un point d'entrée"]);

$entryPointId = $matches[1];
$lyrId = $matches[2];
$zoom = $matches[3];
$x = $matches[4];
$y = $matches[5];
$format = $mimetypes[$matches[6]] ?? null;

$params = Yaml::parseFile(__DIR__.'/tiles.yaml');

if (!isset($params['entryPoints'][$entryPointId]))
  error(404, ['error'=> "Erreur $script_path/$entryPointId ne correspond pas à un point d'entrée"]);

$layers = $params['entryPoints'][$entryPointId]['layers'];
if (!isset($layers[$lyrId]))
  error(404, ['error'=> "Erreur $script_path/$path_info ne correspond pas à une couche"]);

function imgpath(string $path0, int $zoom, int $x, int $y, string $fmt): string {
  return sprintf("%s/%d/%d/%d.%s", $path0, $zoom, $x, $y, $fmt);
}

function cell(string $path0, int $zoom, int $x, int $y, string $fmt) {
  return "<td><a href='".imgpath($path0, $zoom, $x, $y, 'html')."'>"
        ."<img src='".imgpath($path0, $zoom, $x, $y, $fmt)."'></a></td>";
}

if ($format == 'text/html') {
  $path0 = "$script_path/$entryPointId/layers/$lyrId";
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
  echo "<td><a href='",imgpath($path0, $zoom+1, 2*$x, 2*$y, 'html'),"'>",
    "<img src='",imgpath($path0, $zoom, $x, $y, 'jpg'),"'></a></td>";
  echo cell($path0, $zoom, $x+1, $y, 'jpg');
  echo "</tr><tr>\n";
  echo cell($path0, $zoom, $x-1, $y+1, 'jpg');
  echo cell($path0, $zoom, $x, $y+1, 'jpg');
  echo cell($path0, $zoom, $x+1, $y+1, 'jpg');
  echo "</tr></table>\n";
  die();
}

$gpname2 = null;
if (isset($layers[$lyrId]['gpname']))
  $gpname = $layers[$lyrId]['gpname'];
else {
  $gpname = $layers[$lyrId]['gpnames'][0];
  $gpname2 = $layers[$lyrId]['gpnames'][1];
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

if (!isset($layers[$lyrId]['protocol'])) { // par défaut protocole WMTS
  $style = (isset($layers[$lyrId]['style']) ? $layers[$lyrId]['style'] : 'normal');
  $url = $params['entryPoints'][$entryPointId]['distribution']['wmts']['url'].'?'
        .'service=WMTS&version=1.0.0&request=GetTile'
        .'&tilematrixSet=PM&height=256&width=256'
        ."&layer=$gpname&format=$format&style=$style"
        ."&tilematrix=$zoom&tilecol=$x&tilerow=$y";
  if ($gpname2)
    $url2 = $params['entryPoints'][$entryPointId]['distribution']['wmts']['url'].'?'
          .'service=WMTS&version=1.0.0&request=GetTile'
          .'&tilematrixSet=PM&height=256&width=256'
          ."&layer=$gpname2&format=$format&style=$style"
          ."&tilematrix=$zoom&tilecol=$x&tilerow=$y";
  $referer = $params['entryPoints'][$entryPointId]['distribution']['wmts']['referer'];
}
elseif ($layers[$lyrId]['protocol']=='WMS') { // sauf si explicitement WMS
  $style = (isset($layers[$lyrId]['style']) ? $layers[$lyrId]['style'] : '');
  $url = $params['entryPoints'][$entryPointId]['distribution']['wms']['url'].'?'
        .'service=WMS&version=1.3.0&request=GetMap'
        ."&layers=$gpname&format=".urlencode($format)."&styles=$style"
        .($format=='image/png' ? '&transparent=true' : '')
        .'&crs='.urlencode('EPSG:3857').'&bbox='.implode(',',bbox($zoom,$x,$y))
        .'&height=256&width=256';
  $referer = $params['entryPoints'][$entryPointId]['distribution']['wms']['referer'];
  //  die("url=<a href='$url'>$url</a>\n");
} else
  error(500, "protocole ".$layers[$lyrId]['protocol']." inconnu");

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
  'timeout' => 60, // 1 min.
  'header'=>"Accept-language: en\r\n"
           .($referer?"referer: $referer\r\n":''),
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
$errorMessage = (isset($errorMessages[$errorCode]) ? $errorMessages[$errorCode] : "HTTP/1.1 $errorCode Error");
header($errorMessage);
header("Content-Type: text/plain; charset=utf-8");
die("$errorMessage\nErreur $errorCode sur $url");
