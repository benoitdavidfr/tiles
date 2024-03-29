<?php
/*PhpDoc:
name:  http.inc.php
title: http.inc.php - tests de restitution par un client de l'erreur générée par l'API tiles
doc: |
  La classe Http définit la méthode open() qui ouvre un flux http
  En cas d'appel du fichier une page html permet de définir une URL et des headers

  Bug:
    - ne focntionne pas si 'Transfer-Encoding: chunked'
      car la fin du bloc n'est pas prise en compte
classes:
*/
{ // exemples d'URL 
  "
**Exemples d'URL:
**Image inexistante:
http://wxs.ign.fr/choisirgeoportail/geoportail/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.MAPS.SCAN-EXPRESS.STANDARD&TILEMATRIXSET=PM&TILEMATRIX=0&TILECOL=1&TILEROW=0&STYLE=normal&FORMAT=image/jpeg
**Image censée exister:
http://wxs.ign.fr/choisirgeoportail/geoportail/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.MAPS.SCAN-EXPRESS.STANDARD&TILEMATRIXSET=PM&TILEMATRIX=0&TILECOL=0&TILEROW=0&STYLE=normal&FORMAT=image/jpeg
** carte/8/129/89
http://wxs.ign.fr/choisirgeoportail/geoportail/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.MAPS&TILEMATRIXSET=PM&TILEMATRIX=8&TILECOL=129&TILEROW=89&STYLE=normal&FORMAT=image/jpeg
http://wxs.ign.fr/choisirgeoportail/geoportail/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.MAPS&TILEMATRIXSET=PM&TILEMATRIX=8&TILECOL=129&TILEROW=89&STYLE=normal&FORMAT=image%2Fjpeg
http://wxs.ign.fr/choisirgeoportail/geoportail/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.MAPS&TILEMATRIXSET=PM&TILEMATRIX=8&TILECOL=129&TILEROW=89&STYLE=normal
http://wxs.ign.fr/choisirgeoportail/geoportail/wmts?SERVICE=WMTS&REQUEST=GetCapabilities

ll0dlgs8phk2hjhmtfyqp47v
http://wxs.ign.fr/ll0dlgs8phk2hjhmtfyqp47v/geoportail/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.MAPS&TILEMATRIXSET=PM&TILEMATRIX=8&TILECOL=129&TILEROW=89&STYLE=normal&FORMAT=image%2Fjpeg
referer: http://benoitdavidfr.github.io/

** error
http://localhost/geoapi/tiles/index.php/igngp/error/12/2027/1439.jpg
** cartes
http://localhost/geoapi/tiles/index.php/igngp/cartes/12/2027/1439.jpg

** test redirection
http://localhost/geoapi/tiles/server.php

** WMS
http://wxs.ign.fr/ll0dlgs8phk2hjhmtfyqp47v/geoportail/r/wms?service=WMS&version=1.3.0&request=GetMap&layers=SCANLITTO_PYR-JPEG_WLD_WM&format=image%2Fjpeg&styles=&crs=EPSG%3A3857&bbox=-130860.19242422,5756625.4742132,-129637.19997166,5757848.4666658&height=256&width=256

";}


function dumpx(string $str) {
  for($i=0; $i < strlen($str); $i++) {
    $char = substr($str, $i, 1);
    echo "$i: '$char', ",bin2hex($char),"<br>\n";
  }
}

/*PhpDoc: functions
  name:  dump
  title: function dump($string) - function affichant la chaine en séparant chaque caractère et en affichant aussi le code hexa correspondant
*/
function dump(string $string) {
  echo "<table border=1>";
  $line = 0;
  while($line*16 < strlen($string)) {
    echo "<tr><td><i>$line</i></td><td>|</td>";
    for ($i=0; $i<16; $i++) {
      if ($line*16 + $i < strlen($string))
        echo "<td>",substr($string,$line*16+$i,1),"</td>";
      else
        echo "<td></td>";
    }
    echo "<td></td>";
    echo "<td>|</td>";
    echo "<td></td>";
    for ($i=0; $i<16; $i++)
      if ($line*16 + $i < strlen($string))
        printf("<td>%02x</td>", ord(substr($string,$line*16+$i,1)));
    echo "</tr>\n";
    $line++;
  }
  echo "</table>\n";
}

/*PhpDoc: classes
name: Http
title: class Http - classes statique portant les fonctions utilisant http
doc: |
  En cas d'erreur et donc de renvoi d'un code http d'erreur, file_get_contents() et fopen() renvoient false interdisant ainsi
  la lecture du message d'erreur. Je ne sais pas dans ce cas accéder au contenu renvoyé.
  La solution consiste à effectuer l'ouverture du stream par fsockopen() et à simuler le protocole http en envoyant une
  commande GET puis à décoder le message retourné commencant par l'en-tête http.
methods:
*/
class Http {
  const URLPATTERN = '!^(https?)://([^/]+)(.*)$!';
  
  /*PhpDoc: methods
  name: getOpen
  title: "static function open(string $url, array $requestHeaders=[], array $options=[]): array - ouverture d'un flux http"
  doc: |
    Retourne un array
    en cas d'erreur non http ['status'=> code<0, 'errno'=>errno, 'errstr'=> errstr]
    en cas de succès http ['status'=> httpStatusCode, 'statusLabel'=> httpStatusLabel, 'headers'=> $headers, 'stream'=> $fp]
    lève une exception si l'url initiale ne respecte pas le motif URLPATTERN
    Les options possibles sont:
      - method - GET, POST, ou n'importe quelle autre méthode HTTP supportée par le serveur distant. Par défaut, vaut GET.
      - timeout - durée du timeout, par défaut 30
      - max_redirects - nbre max de redirections, par défaut 20, si <= 1 alors pas de redirection
      - follow_location - Suit les redirections Location. À définir à 0 pour désactiver. Par défaut, vaut 1.
  */
  static function open(string $url, array $requestHeaders=[], array $options=[]): array {
    if (!preg_match(self::URLPATTERN, $url, $matches))
      throw new Exception ("l'url $url ne correspond pas au motif");
    $protocol = $matches[1];
    $host = $matches[2];
    $path = $matches[3];
    $port = $protocol == 'https' ? 443 : 80;
    $method = $options['method'] ?? 'GET';
    if ($method <> 'GET')
      throw new Exception("Méthode HTTP $method non implémentée");
    $timeout = $options['timeout'] ?? 30;
    $max_redirects = $options['max_redirects'] ?? 20;
    $follow_location = $options['follow_location'] ?? 1;
    $errno = 0;
    $errstr = '';
    if (FALSE === $fp = @fsockopen($host, $port, $errno, $errstr, $timeout))
      return ['status'=> -1, 'errno'=> $errno, 'errstr'=> $errstr];
    $out = "GET $path HTTP/1.1\r\n"
         . "Host: $host\r\n";
    //echo "<pre>"; var_dump($requestHeaders); die();
    foreach ($requestHeaders as $key => $val) {
      if (is_int($key))
        $out .= "$val\r\n";
      else
        $out .= "$key: $val\r\n";
    }
    $out .= "Connection: Close\r\n\r\n";
    //echo "<pre>"; var_dump($out); die();
    if (!fwrite($fp, $out))
      return ['status'=> -2, 'errno'=> 0, 'errstr'=> "Erreur dans fwrite()"];
    if (0) { // affichage brut du retour
      echo "<pre>\n";
      while ($buff = fgets($fp)) {
        var_dump($buff);
        dump($buff);
      }
      die("FIN ligne ".__LINE__);
    }
    $headers = [];
    $transferEncoding = null;
    while ($header = rtrim(fgets($fp), "\r\n")) {
      $headers[] = $header;
      if ($header == 'Transfer-Encoding: chunked')
        $transferEncoding = 'chunked';
    }
    if (!$headers)
      return ['status'=> -3, 'errno'=> 0, 'errstr'=> "Erreur aucun message http retourné, requête probablement incorrecte"];
    $status = substr($headers[0], 9, 3);
    $statusLabel = substr($headers[0], 13);
    if (!$follow_location || ($max_redirects <= 1) || !in_array($status, [301,302])) {
      if ($transferEncoding == 'chunked') // la première ligne encode la taille du bloc
        $headers[] = 'ChunkedTransferEncodingLength: '.fgets($fp);
      return ['status'=> $status, 'statusLabel'=> $statusLabel, 'headers'=> $headers, 'stream'=> $fp];
    }
    // cas d'une redirection, appel récursif
    fclose($fp);
    $location = null;
    foreach ($headers as $header) {
      if (substr($header, 0, 10) == 'Location: ') {
        $location = substr($header, 10);
        break;
      }
    }
    if (!$location)
      return ['status'=> -4, 'errno'=> 0, 'errstr'=> "Erreur champ Location non défini"];
    if (!preg_match(self::URLPATTERN, $location))
      return ['status'=> -5, 'errno'=> 0, 'errstr'=> "Erreur champ Location contient une URL mal définie"];
    $result = self::open($location, $requestHeaders, [
          'method'=> $method,
          'timeout'=> $timeout,
          'max_redirects'=> $max_redirects-1,
          'follow_location'=> $follow_location]);
    //echo "<pre>"; var_dump($result); echo "</pre>\n";
    if ($result['status'] < 0)
      return $result;
    $result['headers'] = array_merge($headers, $result['headers']);
    return $result;
  }
}


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;

$url = $_GET['url'] ?? '';
$headers = $_GET['headers'] ?? '';
$options = $_GET['options'] ?? '';
echo "<form method=GET><table border=1>";
echo "<tr><td>url</td><td><input type=text size=150 name='url' value=\"",htmlspecialchars($url),"\"></td></tr>\n";
echo "<tr><td>headers</td><td><textarea name='headers' rows=6 cols=120>",htmlspecialchars($headers),"</textarea></td></tr>\n";
echo "<tr><td>options</td><td><textarea name='options' rows=4 cols=120>",htmlspecialchars($options),"</textarea></td></tr>\n";
echo "<tr><td colspan=2 align='center'><input type=submit value='ok'></td></tr>\n";
echo "</table></form>\n";

if ($url) {
  //echo "<pre>"; var_dump($headers); echo "</pre>\n";
  $headers = $headers ? explode("\r\n", $headers) : [];
  //echo "<pre>"; var_dump($headers); echo "</pre>\n";
  //$requestHeaders = ['referer'=> 'http://benoitdavidfr.github.io/'];
  $options2 = [];
  foreach (explode("\r\n", $options) as $option) {
    $pos = strpos($option, '=');
    $name = substr($option, 0, $pos);
    $value = substr($option, $pos+1);
    //echo "pos=$pos, name=$name, value=$value<br>\n";
    $options2[$name] = $value;
  }
  $result = Http::open($url, $headers, $options2);
  echo "<pre>"; var_dump($result); echo "</pre>\n";
  if (isset($result['stream'])) {
    while ($buff = fgets($result['stream'])) {
        echo $buff;
      dump($buff);
    }
  }
}