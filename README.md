# Définition d'un web-service de consultation tuilée
22 juin 2019 (rédaction en cours)

## introduction

L'infrastructure de données et de services géographiques prescrite par la
[directive Inspire](https://eur-lex.europa.eu/eli/dir/2007/2/oj?locale=fr) se révèle complexe à mettre en oeuvre
et difficile à utiliser par les non-spécialistes en géomatique,
notamment les développeurs habitués à utiliser des API.
On se propose donc ici de définir une
[**nouvelle infrastructure**](https://github.com/benoitdavidfr/geoinfra/blob/master/README.md),
appelée *géoinfra*, **plus simple à utiliser** notamemnt pour les non-géomaticiens et cherchant à prendre en compte
les [recommandations W3C/OGC pour la publication de données géographiques sur le web](https://w3c.github.io/sdw/bp/).

Ce document spécifie un web-service de consultation tuilée qui est un service de consultation au sens de la directive Inspire,
c'est à dire qui permet de consulter de l'information géographique sous la forme d'images.
Cette spécification est illustrée dans des exemples listés ci-dessous
qui font appel à un prototype qui sera progressivement complété.

## présentation

Les points forts de ce nouveau service sont les suivants:

  - utilisation du [protocole de service tuilé très populaire défini par OpenStreetMap](https://wiki.openstreetmap.org/wiki/Slippy_map_tilenames)
    permetant ainsi l'utilisation des services dans de nombreux outils ;
  - le motif d'URL défini par le protocole est en outre utilisé comme URI de la couche
    et fournit une documentation de la couche ;
  - les couches sont regroupées en jeux de données, corespondant à un URI dont l'appel fournit une documentation du jeu
    et notamment liste les couches exposées ;
  - les jeux de données sont à leur tour regroupés et listés dans les métadonnées du web-service ;
  - en outre, une couche peut être millésimée, c'est à dire correspondre en fait à plusieurs couches chacune
    pour une année particulière.

### exemples

- <http://tiles.geoapi.fr/ignbase/cartes/16/32945/22940.jpg> - retourne une tuile de la couche cartes du jeu de données ignbase,
- <http://tiles.geoapi.fr/ignbase/cartes/{z}/{x}/{y}.jpg> - identifie et décrit la couche cartes du jeu de données ignbase,
- <http://tiles.geoapi.fr/ignbase> - identifie le jeu données ignbase et décrit les couches exposées,
- <http://tiles.geoapi.fr/> - identifie le web-service et décrit les jeux de données ainsi que que l'API du service
  et fournit des exemples,
- <http://tiles.geoapi.fr/ignbase/cartes/16/32945/22940.html> - retourne une page composée de la tuile désignée
  ainsi que ses 8 voisines permettant d'une part d'interroger le service tuile par tuile,
  et, d'autre part, de naviguer simplement dans la couche,
- <http://tiles.geoapi.fr/ignbase/pleiades{year}/{z}/{x}/{y}.png> - identifie et décrit la couche millésimée des images Pléiades
  correspondant à une couche par millésime,
- <http://tiles.geoapi.fr/ignbase/pleiades2016/{z}/{x}/{y}.png> - identifie et décrit la couche des images Pléiades
  de l'année 2016,
- <http://tiles.geoapi.fr/ignbase/pleiades2016/5/16/11.png> - retourne une tuile de la couche des images Pléiades de l'année 2016,
- <http://tiles.geoapi.fr/ignbase/pleiades{year}/10/507/350.png> - retourne une tuile de la couche des images Pléiades
  de l'année la plus récente,

## complément

<http://geoapi.fr/tiles/map.php>
