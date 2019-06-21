<?php
/*PhpDoc:
name:  htmlviewer.inc.php
title: htmlviewer.inc.php - visualiseur HTML utilisé pour visualiser les tuiles
doc: |
journal: |
  10/6/2019:
    - fork de index.php
*/
require_once __DIR__.'/http.inc.php';

function imgpath(string $path0, int $zoom, int $x, int $y, string $fmt): string {
  return sprintf("%s/%d/%d/%d.%s", $path0, $zoom, $x, $y, $fmt);
}

function cell(string $path0, int $zoom, int $x, int $y, string $fmt) {
  if (($x < 0) || ($y < 0) || ($x >= 2 ** $zoom) || ($y >= 2 ** $zoom))
    return "<td>O</td>";
  return "<td><a href='".imgpath($path0, $zoom, $x, $y, 'html')."'>"
        ."<img src='".imgpath($path0, $zoom, $x, $y, $fmt)."' alt='erreur'></a></td>\n";
}

// Menu HTML pour changer de couche
function selectingAnotherLayer(string $curLyrId, string $dsid, string $zoomout): string {
  $html = "<form><select name='layer'>\n";
  foreach (Catalog::layersByGroup($dsid) as $grpLabel => $lyrGroup) {
    $html.= "<optgroup label='$grpLabel'>\n";
    foreach ($lyrGroup as $lyrId => $layer)
      $html .= "<option".(($lyrId == $curLyrId) ? ' selected':'')." value='$lyrId'>$layer[title]</option>\n";
    $html.= "</optgroup>\n";
  }
  $html .= "</select>\n"
    ."<input type=submit value='changer'>\n"
    .$zoomout
    ."</form>\n";
  return $html;
}

function htmlViewer(string $path0, string $dsid, string $lyrId, $zoom, $x, $y) {
  $zoomout = ($zoom > 0) ? "<a href='".imgpath($path0, $zoom-1, intdiv($x, 2), intdiv($y, 2), 'html')."'>zoom-out</a>\n" : '';
  echo selectingAnotherLayer($lyrId, $dsid, $zoomout);
  $fmt = Catalog::layer($dsid, $lyrId)['format']=='image/png' ? 'png' : 'jpg';
  if ($zoom == 0) {
    echo "<a href='",imgpath($path0, 1, 0, 0, 'html'),"'>",
      "<img src='",imgpath($path0, 0, 0, 0, $fmt),"' alt='erreur 0/0/0.$fmt'></a></td>\n";
  }
  else {
    echo "<table><tr>\n";
    echo cell($path0, $zoom, $x-1, $y-1, $fmt);
    echo cell($path0, $zoom, $x, $y-1, $fmt);
    echo cell($path0, $zoom, $x+1, $y-1, $fmt);
    echo "</tr><tr>\n";
    echo cell($path0, $zoom, $x-1, $y, $fmt);
    echo "<td><a href='",imgpath($path0, $zoom+1, 2*$x+1, 2*$y+1, 'html'),"'>",
      "<img src='",imgpath($path0, $zoom, $x, $y, $fmt),"' alt='erreur $zoom/$x/$y.$fmt'></a></td>\n";
    echo cell($path0, $zoom, $x+1, $y, $fmt);
    echo "</tr><tr>\n";
    echo cell($path0, $zoom, $x-1, $y+1, $fmt);
    echo cell($path0, $zoom, $x, $y+1, $fmt);
    echo cell($path0, $zoom, $x+1, $y+1, $fmt);
    echo "</tr></table>\n";
  }
  
  $url = "$path0/$zoom/$x/$y.$fmt";
  $get = Http::open($url);
  if ($get['status'] < 0) {
    echo "$url -> KO";
    echo "<pre>result="; var_dump($get); echo "</pre>\n";
  }
  else {
    echo "$url -> ok";
    echo "<pre>result="; var_dump($get); echo "</pre>\n";
    if ($get['status'] <> 200) {
      echo "<pre>contents="; var_dump(stream_get_contents($get['stream'])); echo "</pre>\n";
    }
    fclose($get['stream']);
  }
}