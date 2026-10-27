# omnilab/pubchem

PubChem (NCBI), through its PUG-REST API (`https://pubchem.ncbi.nlm.nih.gov/rest/pug/`), for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): compounds by CID, name,
InChIKey, InChI, SMILES, CAS number or another base's identifier, their computed properties,
synonyms, description, 2D structure and cross-references, as entities; a search by name. No key.

## Installation

```sh
composer require omnilab/pubchem
```

```php
use Omnilab\PubChem\PubChemSourceFactory;

$pubchem = (new PubChemSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'synonyms' => 20,                  // optional
    'description' => true,             // optional
]);
```

With the Symfony bundle, the factory is registered as `pubchem` (see the core's docs).

## Credentials

None: PubChem (NCBI) takes no key and no account. Its usage policy asks for five calls a second,
400 a minute and 300 seconds of running time a minute at most; give `mailto`, the address NCBI
writes to before it blocks a caller.

## Options

The factory's options, with the defaults it applies (`PubChemSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `synonyms` | `int` | `20` | no | how many synonyms to keep (the depositors' names, registry numbers and identifiers left out); 0: none, and one call less a compound |
| `description` | `bool` | `true` | no | whether to read the description (one call a compound); `false`: none |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0.2` | no | seconds left between two calls: PubChem allows five a second, so never less than 0.2 |
| `base_uri` | `string` | `https://pubchem.ncbi.nlm.nih.gov/rest/pug/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`, `structure`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a CID, an InChIKey, an InChI, a SMILES (`smiles:`, or a string of SMILES characters only), a CAS number, a ChEBI, ChEMBL, Wikidata, UNII or DSSTox id, a name; another domain's identifier refused before any call; up to four calls a compound |
| `search` | yes | the compounds whose name is the text, then those with it as a word, each once; 100 a page at most, paged by offset with a `total`; a few properties each (`entity()` reads the rest); a domain other than chemistry answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | yes | the image is the PNG of the 2D structure (`compound/cid/{cid}/PNG`) |
| `cross_references` | yes | the InChIKey and the InChI, then the first CAS, ChEBI, ChEMBL, Wikidata, UNII, DSSTox and KEGG id among the synonyms |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

## Calls

| Method | Reads | PUG-REST |
|---|---|---|
| `entity()` | a CID (`2519`, `CID 2519`, `pubchem.cid:2519`, PubChem's page) | `compound/cid/{cid}/property/...`, `/synonyms`, `/description` |
| | an InChIKey | `compound/inchikey/{key}/cids` first |
| | an InChI, a SMILES (`smiles:` or a string with SMILES characters only) | `compound/inchi/cids?inchi=`, `compound/smiles/cids?smiles=` |
| | a CAS number, a ChEBI, ChEMBL, Wikidata, UNII or DSSTox id, a name | `compound/name/cids?name=` |
| `search()` | `Query::$text`; a domain other than chemistry answers nothing | `compound/name/cids?name=` exact, then `&name_type=word`, then the properties of the page's CIDs |

The first CID PubChem gives for a name is taken (its best match). A string that is neither an
identifier nor a name PubChem knows, with the characters of a SMILES only, is tried as a SMILES;
a SMILES PubChem cannot read is `null`, not an error. An identifier of another domain (UniProt,
Ensembl...) is refused before any call.

## What a compound holds

`id` (its CID), `label` (PubChem's title), `domain` chemistry, `kind` compound, `url` (its
PubChem page), `image` (the PNG of its 2D structure: `Capability::STRUCTURE`), `description` (the
first PubChem gives, often ChEBI's), `synonyms` (the depositors' names, registry numbers and
identifiers left out, 20 by default) and these properties, from PubChem:

| Key | PubChem | Value |
|---|---|---|
| `molecular_formula` | MolecularFormula | text |
| `molecular_weight` | MolecularWeight | Quantity, g/mol |
| `exact_mass`, `monoisotopic_mass` | ExactMass, MonoisotopicMass | Quantity, Da |
| `iupac_name` | IUPACName | text |
| `smiles`, `connectivity_smiles` | SMILES, ConnectivitySMILES | text |
| `inchi`, `inchikey` | InChI, InChIKey | text |
| `xlogp` | XLogP (XLogP3, computed) | number |
| `tpsa` | TPSA | Quantity, Å² |
| `complexity`, `charge` | Complexity, Charge | number |
| `h_bond_donors`, `h_bond_acceptors`, `rotatable_bonds`, `heavy_atoms` | HBondDonorCount, HBondAcceptorCount, RotatableBondCount, HeavyAtomCount | number |

`identifiers`: the InChIKey and the InChI, then the first CAS number, ChEBI, ChEMBL, Wikidata,
UNII, DSSTox and KEGG id among the synonyms, in PubChem's order - the primary first (caffeine's CAS
is 58-08-2, not the deprecated 114303-55-8).

A search result holds the title, formula, weight, InChIKey and IUPAC name only: `entity()` reads
the rest.

## What PubChem does not give here

No experimental properties (melting point, solubility, toxicity: they are in PUG-View, per
section, not read here); no substances (SIDs), assays (AIDs), genes or proteins; no similarity or
substructure search; a search by word ranks nothing beyond the exact name. The cross-references
are the depositors' synonyms: what PubChem's own xrefs call (`/xrefs/RN`) adds is not read.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\Scheme;
use Omnilab\PubChem\PubChemSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$pubchem = (new PubChemSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'synonyms' => 5]);

$aspirin = $pubchem->entity('BSYNRYMUTXBXSQ-UHFFFAOYSA-N');   // by InChIKey
echo $aspirin->label, ' (', $aspirin->id, '): ', $aspirin->value('molecular_formula'), ', ', $aspirin->property('molecular_weight'), "\n";
echo '  CAS ', $aspirin->identifier(Scheme::CAS), ', ChEBI ', $aspirin->identifier(Scheme::CHEBI), ', synonyms: ', implode(', ', $aspirin->synonyms), "\n";

$page = $pubchem->search(new Query('caffeine', limit: 3));
printf("%d compounds named \"caffeine\" or with it as a word; the first %d:\n", $page->total, count($page));
foreach ($page as $compound) {
    echo '  ', $compound->id, '  ', $compound->label, '  ', $compound->property('molecular_weight'), "\n";
}
var_dump($pubchem->entity('no such compound'));
```

```
Aspirin (pubchem.cid:2244): C9H8O4, 180.16 g/mol
  CAS 50-78-2, ChEBI 15365, synonyms: ACETYLSALICYLIC ACID, 2-Acetoxybenzoic acid, 2-(Acetyloxy)benzoic acid, Acylpyrin, O-Acetylsalicylic acid
233 compounds named "caffeine" or with it as a word; the first 3:
  pubchem.cid:2519  Caffeine  194.19 g/mol
  pubchem.cid:9871508  Acetaminophen; Aspirin; Caffeine  525.5 g/mol
  pubchem.cid:56841593  Acetaminophen; Butalbital; Caffeine; Codeine Phosphate  869 g/mol
NULL
```

(as answered on 2026-10-27; a name PubChem does not know is its 404, null)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        pubchem: { factory: pubchem, options: { mailto: '%env(OMNILAB_MAILTO)%', synonyms: 20, description: true } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

PubChem's own answers: its `Fault` (`{"Fault": {"Message", "Details"}}`) is read into the message
of a `ProviderException`; its 503 "server busy" is an `UnavailableException`. Before any call, an
identifier of a scheme PubChem does not keep (UniProt, Ensembl, a GBIF key...) is a
`NotSupportedException`. A SMILES or an InChI PubChem cannot read (its 400 "Unable to convert input
into a compound object") is null, not an error; the same 400 for a name stays a
`ProviderException`.

## Verified, and not

The tests (ten) read answers recorded from PubChem on 2026-10-15 (caffeine, CID 2519): its CID by name, its
properties, its synonyms (cut from 413 to 59, the identifiers kept), its description, a search by
word (cut from 230 CIDs to 12) and the properties of its first page, a name PubChem does not know
(404) and a CID that does not exist. The tests run on Symfony's `MockHttpClient`.

Against PubChem on 2026-10-27: aspirin by its InChIKey, the search for "caffeine" (233 compounds),
a name PubChem does not know (the example above); caffeine by its name, in the core's installation
script. Not verified: the per-minute budget and PubChem's block past it (never reached).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by PubChem, the NCBI or the U.S. National Library of Medicine. PubChem's
[usage policy](https://pubchem.ncbi.nlm.nih.gov/docs/programmatic-access) limits the calls, some
depositors attach terms to what they deposited, and NCBI's
[policies](https://www.ncbi.nlm.nih.gov/home/about/policies/) apply, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-pubchem#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-pubchem#responsibility)
section.
