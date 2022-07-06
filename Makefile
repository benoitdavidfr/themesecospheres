all: thespheres.ttl thespheres.json thespheres-ld.yaml thespheres.xml
clean:
	rm thespheres.ttl thespheres.json thespheres-ld.yaml thespheres.xml
thespheres.ttl: skosify.php thespheres.yaml
	php skosify.php thespheres.yaml > thespheres.ttl
thespheres.json: skosify.php thespheres.yaml
	php skosify.php thespheres.yaml cjsonld > thespheres.json
thespheres-ld.yaml: skosify.php thespheres.yaml
	php skosify.php thespheres.yaml cyamlld > thespheres-ld.yaml
thespheres.xml: skosify.php thespheres.yaml
	php skosify.php thespheres.yaml rdfxml > thespheres.xml
