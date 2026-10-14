# omnilab/pubchem

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

## Tests

`Tests/Fixtures` holds answers recorded on 2026-10-15 (caffeine, CID 2519): its CID by name, its
properties, its synonyms (cut from 413 to 59, the identifiers kept), its description, a search by
word (cut from 230 CIDs to 12) and the properties of its first page, a name PubChem does not know
(404) and a CID that does not exist. The tests run on Symfony's `MockHttpClient`.
