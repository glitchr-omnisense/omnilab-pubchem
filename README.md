# omnilab/pubchem

PubChem for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a compound by its CID,
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

## Install and a first call

```sh
composer require omnilab/pubchem
```

A real call, with no account and no key: caffeine in PubChem, by its CID (no synonyms nor
description: one call less each). `mailto` is your address, sent in the User-Agent so that the
service can reach you before it blocks a caller; the source keeps to the limits its
[`policy.json`](policy.json) declares.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\PubChem\PubChemSourceFactory;

$pubchem = (new PubChemSourceFactory())->create(['mailto' => 'you@example.org', 'synonyms' => 0, 'description' => false]);   // your address, in the User-Agent
$caffeine = $pubchem->entity('2519');
echo $caffeine->label, ', ', $caffeine->value('molecular_formula'), ', ', $caffeine->property('molecular_weight'), "\n";
```

Its output on 2026-11-13:

```
Caffeine, C8H10N4O2, 194.19 g/mol
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

## Usage policy

Declared as data in [`policy.json`](policy.json) (its pages could not be read on 2026-11-10 (https://pubchem.ncbi.nlm.nih.gov/docs/programmatic-access): see the notes in the file), and honoured on every request by the
core: a limit reached waits when its window ends within `max_wait` seconds, else the request is not
sent ([Usage policies](https://github.com/glitchr-omnisense/omnilab/blob/1.x/docs/policies.md)).

- Limits: 5 a second; 400 a minute.
- Licence: not stated; commercial use: unknown.
- Credit: not required: PubChem, National Center for Biotechnology Information (NLM/NIH) (https://pubchem.ncbi.nlm.nih.gov/).
- Needs: nothing.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NCBI (PubChem). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
