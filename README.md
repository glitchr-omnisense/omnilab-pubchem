# omnilab/pubchem

PubChem for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab): a compound by its CID,
a name, an InChIKey, an InChI, a CAS number, a SMILES, or an identifier of another base PubChem
keeps among its synonyms (ChEBI, ChEMBL, Wikidata, UNII, DSSTox); its computed properties with
their units, its synonyms, its description, the image of its 2D structure, and its identifiers in
other bases for the Collector to follow; a search by name. No key.

```php
$pubchem = (new PubChemSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$caffeine = $pubchem->entity('caffeine');
$caffeine->property('molecular_weight');        // 194.19 g/mol
$caffeine->value('tpsa');                       // Quantity: 58.4 Å²
$caffeine->identifier(Scheme::CHEBI);           // 27732
$caffeine->image;                               // https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/2519/PNG
$pubchem->entity('CN1C=NC2=C1C(=O)N(C(=O)N2C)C'); // the same, by its SMILES
$pubchem->search(new Query('caffeine', limit: 10));
```

```yaml
omnilab:
    sources:
        pubchem:
            factory: pubchem
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent: whom NCBI writes to before it blocks a caller
                synonyms: 20                   # how many to keep; 0: none, one call less per compound
                description: true              # false: one call less per compound
                throttle: 0.2                  # PubChem allows five calls a second: never lower
```

## Terms of use

PubChem is run by the NCBI (U.S. National Library of Medicine), free and without a key. Its
[usage policy](https://pubchem.ncbi.nlm.nih.gov/docs/programmatic-access) asks for at most five
calls a second, 400 a minute and 300 seconds of running time a minute; a caller over it is
blocked for a while. The source leaves 0.2 s between two calls and a compound costs up to four:
cache what it answers. PubChem's records are free to use; some depositors attach terms to what
they deposited (each source's page on PubChem says), and NCBI's
[policies](https://www.ncbi.nlm.nih.gov/home/about/policies/) apply.

See [docs/](docs/index.md) for what is read, how it maps, and what PubChem does not give here.

License: MIT.
