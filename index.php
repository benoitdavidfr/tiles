<?php
/*PhpDoc:
name:  index.php
title: index.php - service de tuiles simplifiant l'accès aux ressources notamment du GP IGN
functions:
classes:
doc: |
  Service de tuiles au std OSM simplifiant l'accès au WMTS du GP IGN
  Fonctionnalités:
    - appel sans clé
    - utilisation du protocole OSM
    - association à chaque couche d'un URI
    - simplification des paramètres / WMTS
    - simplification des noms de couches
    - ajout de couches non disponibles en WMTS
    - documentation intégrée
    - couche cartes plus simple d'emploi
    - mise en cache pour 21 jours (la durée pourrait dépendre du zoom)
  A FAIRE:
    - pourquoi prendre le format passé en paramètre et pas celui défini pour la couche ???
journal: |
  21/6/2019:
    - création de la classe Catatalog pour mutualiser le code
    - ajout de la possibilité de paramétrer une couche par un millésime
  20/6/2019:
    - utilisation de Http::open() à la place de file_get_contents() dans l'appel http
      afin de récupérer et retourner le message d'erreur
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
includes: [ '../../vendor/autoload.php', http.inc.php ]
*/
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/http.inc.php';

use Symfony\Component\Yaml\Yaml;

$version = '2019-06-21T08:30:00';
$path_info = $_SERVER['PATH_INFO'] ?? null;
$script_path = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";

$mimetypes = [
  'jpg' => 'image/jpeg',
  'png' => 'image/png',
  'html' => 'text/html',
];

/*PhpDoc: functions
name: error
title: function error(int $code, array $message) - envoi un message d'erreur JSON associé à un code HTTP
*/
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

/*PhpDoc: classes
name: Catalog
title: class Catalog - lit le catalogue Yaml et exploite son contenu
*/
class Catalog {
  const FILE = __DIR__.'/tiles.yaml';
  static $script_path;
  static $path_info;
  static $params=null; // les paramètres stockés dans tiles.yaml
  
  // initialise
  static function init(): void {
    self::$script_path = "http://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";
    self::$path_info = $_SERVER['PATH_INFO'] ?? null;
    self::$params = Yaml::parseFile(self::FILE);
  }
  
  // retourne l'array correspondant à un dataset
  static function dataset(string $dsid): ?array {
    if (!self::$params) self::init();
    return self::$params['datasets'][$dsid] ?? null;
  }
  
  static function layersByGroup(string $dsid): array {
    if (!self::$params) self::init();
    if (!($dataset = self::dataset($dsid)))
      error(404, ['error'=> "Erreur ".self::$script_path."/$dsid ne correspond pas à un jeu de données"]);
    return $dataset['layersByGroup'];
  }
  
  // retourne l'objet layer correspondant à $dsid et $lyrId
  static function layer(string $dsid, string $lyrId): ?array {
    if (!self::$params) self::init();
    if (!($dataset = self::dataset($dsid)))
      error(404, ['error'=> "Erreur ".self::$script_path."/$dsid ne correspond pas à un jeu de données"]);
    foreach ($dataset['layersByGroup'] as $lyrGroup) {
      if (isset($lyrGroup[$lyrId]))
        return $lyrGroup[$lyrId];
    }
    // si l'URI demandée est un millésime d'une couche définie alors l'info millésimée est retournée
    foreach ($dataset['layersByGroup'] as $lyrGroup) {
      foreach ($lyrGroup as $lyrId2 => $layer) {
        if (strpos($lyrId2, '{year}') !== false) {
          //echo $lyrId2;
          $pattern = str_replace('{year}', '([0-9][0-9][0-9][0-9])', $lyrId2);
          //echo $pattern;
          if (preg_match("!^$pattern$!", $lyrId, $matches)) {
            $year = (int)$matches[1];
            $layer['title'] = str_replace('{year}', $year, $layer['titleYear']);
            unset($layer['titleYear']);
            $layer['abstract'] = str_replace('{year}', $year, $layer['abstractYear']);
            unset($layer['abstractYear']);
            //$layer['year'] = $year;
            unset($layer['years']);
            if (isset($layer['gpname']))
              $layer['gpname'] = str_replace('{year}', $year, $layer['gpname']);
            else {
              $gpnames = [];
              foreach ($layer['gpnames'] as $gpname)
                $gpnames[] = str_replace('{year}', $year, $gpname);
              $layer['gpnames'] = $gpnames;
            }
            return $layer;
          }
        }
      }
    }
    error(404, ['error'=> "Erreur ".self::$script_path.self::$path_info." ne correspond pas à une couche"]);
  }
  
  static function distribution(string $dsid): array {
    if (!self::$params) self::init();
    if (!($dataset = self::dataset($dsid)))
      error(404, ['error'=> "Erreur ".self::$script_path."/$dsid ne correspond pas à un jeu de données"]);
    return $dataset['distribution'];
  }
};

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
  
  if (!($dataset = Catalog::dataset($dsid)))
    error(404, ['error'=> "Erreur $script_path/$path_info ne correspond pas à un jeu de données"]);
  
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
  $layer = Catalog::layer($dsid, $lyrId);
  $lyrFmt = ($layer['format']=='image/png' ? 'png' : 'jpg');
  if ($fmt && ($lyrFmt <> $fmt))
    error(404, ['error'=> "Erreur sur $script_path$path_info, format $fmt incorrect"]);
  
  header('Content-type: application/json');
  $result = [
    'title'=> $layer['title'],
    'self'=> "$script_path$path_info",
    'abstract'=> $layer['abstract'],
  ];
  if (isset($layer['source']))
    $result['source'] = $layer['source'];
  if (isset($layer['years'])) {
    foreach ($layer['years'] as $year)
      $result['years'][$year] = "$script_path/$dsid/".str_replace('{year}', $year, $lyrId)."/{z}/{x}/{y}.$lyrFmt";
  }
  $result = array_merge($result, [
    'format'=> $layer['format'],
    'minZoom'=> $layer['minZoom'],
    'maxZoom'=> $layer['maxZoom'],
    'tileUrlPattern'=> "$script_path/$dsid/$lyrId/{z}/{x}/{y}.$lyrFmt",
  ]);
  die(json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

// "/{dsid}/{lyrId}/html"- doc de la couche en HTML
if (preg_match('!^/([^/]*)/([^/]*)/html$!', $path_info, $matches)) {
  $dsid = $matches[1];
  $lyrId = $matches[2];
  $fmt = isset($matches[3]) ? $matches[4] : null;
  
  if (!($layer = Catalog::layer($dsid, $lyrId))) {
    header('HTTP/1.1 404 Not Found');
    echo "Erreur $script_path/$dsid/$lyrId ne correspond pas à une couche<br>\n";
    die();
  }
  $lyrFmt = ($layer['format']=='image/png' ? 'png' : 'jpg');
  
  echo "<h3>$layer[title] ($lyrId)</h3>\n",
    "<table border=1>",
    "<tr><td><i>title</i></td><td>$layer[title]</td></tr>",
    "<tr><td><i>abstract</i></td><td>$layer[abstract]</td></tr>",
    isset($layer['year']) ? "<tr><td><i>year</i></td><td>$layer[year]</td></tr>" : '',
    "<tr><td><i>format</i></td><td>$layer[format]</td></tr>",
    "<tr><td><i>minZoom</i></td><td>$layer[minZoom]</td></tr>",
    "<tr><td><i>maxZoom</i></td><td>$layer[maxZoom]</td></tr>",
    "<tr><td><i>tileUrlPattern</i></td><td>$script_path/$dsid/$lyrId/{z}/{x}/{y}.$lyrFmt</td></tr>",
    "</table>\n";
  die();
}

// "/{dsid}/layers/{lyrId}/{zoom}/{x}/{y}.{fmt}" - une tuile ou une page de tuiles
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

if (!($layer = Catalog::layer($dsid, $lyrId)))
  error(404, ['error'=> "Erreur $script_path/$path_info ne correspond pas à une couche"]);

//print_r($layer);
if ($format == 'text/html') {
  require_once __DIR__.'/htmlviewer.inc.php';
  htmlViewer("$script_path/$dsid/$lyrId", $dsid, $lyrId, $zoom, $x, $y);
  die();
}

$gpname2 = null;
if (isset($layer['gpname'])) {
  $gpname = $layer['gpname'];
}
elseif (isset($layer['gpnames'])) {
  $gpname = $layer['gpnames'][0];
  $gpname2 = $layer['gpnames'][1];
}
else
  error(500, "gpname non défini pour $script_path/$path_info");
if (isset($layer['years'])) {
  $year = $layer['years'][0];
  $gpname = str_replace('{year}', $year, $gpname);
  $gpname2 = $gpname2 ? str_replace('{year}', $year, $gpname2) : null;
}
//print_r($matches);

//echo "gpname=$gpname";

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

if (!isset($layer['protocol'])) { // par défaut protocole WMTS
  $style = $layer['style'] ?? 'normal';
  $url = Catalog::distribution($dsid)['wmts']['url'].'?'
        .'service=WMTS&version=1.0.0&request=GetTile'
        .'&tilematrixSet=PM&height=256&width=256'
        ."&layer=$gpname&format=$format&style=".urlencode($style)
        ."&tilematrix=$zoom&tilecol=$x&tilerow=$y";
  if ($gpname2)
    $url2 = Catalog::distribution($dsid)['wmts']['url'].'?'
          .'service=WMTS&version=1.0.0&request=GetTile'
          .'&tilematrixSet=PM&height=256&width=256'
          ."&layer=$gpname2&format=$format&style=".urlencode($style)
          ."&tilematrix=$zoom&tilecol=$x&tilerow=$y";
  $referer = Catalog::distribution($dsid)['wmts']['referer'];
}
elseif ($layers[$lyrId]['protocol']=='WMS') { // sauf si explicitement WMS
  $style = $layers[$lyrId]['style'] ?? '';
  $url = Catalog::distribution($dsid)['wms']['url'].'?'
        .'service=WMS&version=1.3.0&request=GetMap'
        ."&layers=$gpname&format=".urlencode($format)."&styles=".urlencode($style)
        .($format=='image/png' ? '&transparent=true' : '')
        .'&crs='.urlencode('EPSG:3857').'&bbox='.implode(',',bbox($zoom,$x,$y))
        .'&height=256&width=256';
  $referer = Catalog::distribution($dsid)['wms']['referer'];
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

if (0) { // Utilisation de file_get_contents()
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
}
else { // Utilisation de Http::open()
  $get = Http::open($url, ["referer: $referer"], ['timeout'=> 10]);
  if ($get['status'] == 200) {
    $data = stream_get_contents($get['stream']);
    sendData($format, $data);
  }
  if (($get['status'] == 404) && $gpname2) {
    $get = Http::open($url2, ["referer: $referer"], ['timeout'=> 10]);
    if ($get['status'] == 200) {
      $data = stream_get_contents($get['stream']);
      sendData($format, $data);
    }
    $url = $url2;
  }
  if ($get['status'] < 0) 
    error(500, ["erreur $get[errstr] dans l'appel de $url"]);
  else {
    $errorText = stream_get_contents($get['stream']);
    fclose($get['stream']);
    $pattern = '!<ExceptionReport><Exception exceptionCode="([^"]*)">([^<]*)</Exception></ExceptionReport>!';
    if (preg_match($pattern, $errorText, $matches)) {
      error($get['status'], ['exceptionCode'=> $matches[1],'exceptionMessage'=> $matches[2], 'url'=> $url]);
    }
    else {
      $errorMessages = [
        400 => 'HTTP/1.1 400 Bad request',
        403 => 'HTTP/1.1 403 Forbidden',
        404 => 'HTTP/1.1 404 Not Found',
      ];
      $errorMessage = $errorMessages[$get['status']] ?? "HTTP/1.1 $errorCode Error";
      error($get['status'], ['error'=> "$errorMessage on $url"]);
    }
  }
}