# Définition d'un web-service de consultation tuilé
11 juin 2019 (rédaction en cours)

### introduction

Ce document s'inscrit dans la définition d'une [nouvelle infrastructure de données et de services géographiques](https://github.com/benoitdavidfr/geoinfra/blob/master/README.md).


### web-service de consultation tuilée<a id='tiles'></a>

- expose un ensemble de couches correspondant chacune à une image géoréférencée PNG ou JPG
- est identifié par un URI de base (basepath)
- définit les points d'accès (endpoints) suivants:
  - / renvoie la description du service et des données exposées ainsi que la liste des couches exposées
  - `{name}/{z}/{x}/{y}.(png|jpg)` renvoie la description de la couche (où {z}, {x}, {y} ne sont pas des paramètres)
  - `{name}/{z}/{x}/{y}.(png|jpg)` renvoie l'image correspondant à la tuile (où {z}, {x}, {y} sont des paramètres)
      - documenté dans <https://wiki.openstreetmap.org/wiki/Slippy_map_tilenames>
  - `{name}/{z}/{x}/{y}.html` renvoie une page html compsée de 9 tuiles permettant la navigation

On reprend ici un format très utilisé sur le web et popularisé par OSM
en lui ajoutant d'une part un mécanisme de description du service, de la liste des couches et de chaque couche
et, d'autre part, une possibilité d'affichage HTML avec navigation dans les tuiles (pan/zoom).

#### exemples

- <http://tiles.geoapi.fr/igngp> - identifie le web-service exposant sous la forme de tuiles les données du Géportail IGN et décrit les couches exposées
- <http://tiles.geoapi.fr/igngp/cartes/{z}/{x}/{y}.jpg> - identifie et décrit la couche cartes exposée sur le Géportail
- <http://tiles.geoapi.fr/igngp/cartes/16/32945/22940.jpg> - retourne une tuile
- <http://tiles.geoapi.fr/igngp/cartes/16/32945/22940.html> - retourne une page composée de 9 tuiles
  permettant la navigation dans la couche

