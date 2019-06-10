<?php
/*PhpDoc:
name:  htmlviewer.inc.php
title: htmlviewer.inc.php - visualiseur HTML utilisé pour visualiser les tuiles
doc: |
journal: |
  10/6/2019:
    - fork de index.php
*/

// ouvre un URL HTTP ou HTTPS et retourne un pointeur de fichier positionné sur le corps du message de retour
// qui peut être utilisé avec d'autres fonctions fichiers, telles fgets(), fgetss(), fputs(), fclose() et feof().
// En cas de succès l'en-tête du message est retournée dans la varaible $headers
// Si l'URL ne correspond à la syntaxe des URL alors génère une exception.
// Si l'appel échoue, la fonction retourne FALSE et $headers contient la description de l'erreur.
function httpOpen(string $url, array &$headers, float $timeout=30) {
  if (!preg_match('!^(https?)://([^/]+)(.*)$!', $url, $matches))
    throw new Exception ("l'url $url ne correspond pas au motif");
  $protocol = $matches[1];
  $host = $matches[2];
  $path = $matches[3];
  $port = $protocol == 'https' ? 443 : 80;
  $errno = 0;
  $errstr = '';
  if (FALSE === $fp = fsockopen($host, $port, $errno, $errstr, $timeout)) {
    $headers = ['errno'=> $errno, 'errstr'=> $errstr];
    return FALSE;
  }
  $out = "GET $path HTTP/1.1\r\n"
       . "Host: $host\r\n"
       . "Connection: Close\r\n\r\n";
  fwrite($fp, $out);
  $headers = [];
  while ($header = fgets($fp)) {
    if (!($header = rtrim($header, "\r\n")))
      return $fp;
    else
      $headers[] = $header;
  }
  return $fp;
}

function imgpath(string $path0, int $zoom, int $x, int $y, string $fmt): string {
  return sprintf("%s/%d/%d/%d.%s", $path0, $zoom, $x, $y, $fmt);
}

function cell(string $path0, int $zoom, int $x, int $y, string $fmt) {
  return "<td><a href='".imgpath($path0, $zoom, $x, $y, 'html')."'>"
        ."<img src='".imgpath($path0, $zoom, $x, $y, $fmt)."' alt='erreur'></a></td>\n";
}

// Menu HTML pour changer de couche
function selectingAnotherLayer(string $curLyrId, array $layers, string $zoomout): string {
  $html = "<form><select name='layer'>";
  foreach ($layers as $lyrId => $layer)
    $html .= "<option".(($lyrId == $curLyrId) ? ' selected':'').">$lyrId";
  $html .= "</select>"
    ."<input type=submit value='changer'>"
    .$zoomout
    ."</form>\n";
  return $html;
}

function htmlViewer(string $path0, array $layers, string $lyrId, $zoom, $x, $y) {
  $zoomout = ($zoom > 1) ? "<a href='".imgpath($path0, $zoom-1, intdiv($x, 2), intdiv($y, 2), 'html')."'>zoom-out</a>\n" : '';
  echo selectingAnotherLayer($lyrId, $layers, $zoomout);
  $fmt = $layers[$lyrId]['format']=='image/png' ? 'png' : 'jpg';
  echo "<table><tr>\n";
  if ($y > 1) {
    echo cell($path0, $zoom, $x-1, $y-1, $fmt);
    echo cell($path0, $zoom, $x, $y-1, $fmt);
    echo cell($path0, $zoom, $x+1, $y-1, $fmt);
    echo "</tr><tr>\n";
  }
  echo cell($path0, $zoom, $x-1, $y, $fmt);
  echo "<td><a href='",imgpath($path0, $zoom+1, 2*$x+1, 2*$y+1, 'html'),"'>",
    "<img src='",imgpath($path0, $zoom, $x, $y, $fmt),"' alt='erreur $zoom/$x/$y.$fmt'></a></td>\n";
  echo cell($path0, $zoom, $x+1, $y, $fmt);
  echo "</tr><tr>\n";
  echo cell($path0, $zoom, $x-1, $y+1, $fmt);
  echo cell($path0, $zoom, $x, $y+1, $fmt);
  echo cell($path0, $zoom, $x+1, $y+1, $fmt);
  echo "</tr></table>\n";
  
  $headers = [];
  $url = "$path0/$zoom/$x/$y.$fmt";
  if (($fp = httpOpen($url, $headers)) === FALSE) {
    echo "$url -> KO";
    echo "<pre>error="; var_dump($headers); echo "</pre>\n";
  }
  else {
    echo "$url -> ok";
    echo "<pre>headers="; var_dump($headers); echo "</pre>\n";
    if (($httpErrorCode = substr($headers[0], 9, 3)) <> 200) {
      echo "<pre>get_contents="; var_dump(stream_get_contents($fp)); echo "</pre>\n";
    }
    fclose($fp);
  }
}