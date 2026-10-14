<?php

namespace Omnilab\PubChem;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PubChem (pubchem.ncbi.nlm.nih.gov), through PUG-REST: a compound by its
 * CID, a name, an InChIKey, an InChI, a CAS number, a SMILES, or an
 * identifier of another base that PubChem keeps among its synonyms (ChEBI,
 * ChEMBL, Wikidata, UNII, DSSTox); its computed properties with their units,
 * its synonyms, its description (from the depositor PubChem names), the
 * image of its 2D structure, and its identifiers in other bases.
 *
 * No key. PubChem asks for at most five calls a second and 400 a minute:
 * the source leaves 0.2 s between two calls (the "throttle" option), and a
 * compound costs up to four (its CID, properties, synonyms, description).
 * A 503 ("server busy") is an UnavailableException.
 */
final class PubChemSource extends HttpSource
{
    public const BASE_URI = 'https://pubchem.ncbi.nlm.nih.gov/rest/pug/';

    /** The most CIDs one property call is asked for. */
    public const MAX_PER_PAGE = 100;

    /** PubChem's property => the key, the label and the unit Omnilab gives it (null: a text or a pure number). */
    private const PROPERTIES = [
        'MolecularFormula' => ['molecular_formula', 'Molecular formula', null],
        'MolecularWeight' => ['molecular_weight', 'Molecular weight', 'g/mol'],
        'ExactMass' => ['exact_mass', 'Exact mass', 'Da'],
        'MonoisotopicMass' => ['monoisotopic_mass', 'Monoisotopic mass', 'Da'],
        'IUPACName' => ['iupac_name', 'IUPAC name', null],
        'SMILES' => ['smiles', 'SMILES', null],
        'ConnectivitySMILES' => ['connectivity_smiles', 'Connectivity SMILES', null],
        'InChI' => ['inchi', 'InChI', null],
        'InChIKey' => ['inchikey', 'InChIKey', null],
        'XLogP' => ['xlogp', 'XLogP3 (computed octanol/water partition coefficient)', null],
        'TPSA' => ['tpsa', 'Topological polar surface area', 'Å²'],
        'Complexity' => ['complexity', 'Complexity', null],
        'Charge' => ['charge', 'Formal charge', null],
        'HBondDonorCount' => ['h_bond_donors', 'Hydrogen bond donors', null],
        'HBondAcceptorCount' => ['h_bond_acceptors', 'Hydrogen bond acceptors', null],
        'RotatableBondCount' => ['rotatable_bonds', 'Rotatable bonds', null],
        'HeavyAtomCount' => ['heavy_atoms', 'Heavy atoms', null],
    ];

    /** The properties a search result is read with: enough to tell compounds apart. */
    private const SEARCH_PROPERTIES = ['Title', 'MolecularFormula', 'MolecularWeight', 'InChIKey', 'IUPACName'];

    /** The schemes PubChem finds a compound by: its own, the structure's, and those it keeps among the synonyms. */
    private const SCHEMES = [Scheme::PUBCHEM_CID, Scheme::INCHIKEY, Scheme::INCHI, Scheme::CAS, Scheme::CHEBI, Scheme::CHEMBL, Scheme::WIKIDATA, Scheme::UNII, Scheme::DSSTOX];

    /** The cross-references read from the synonyms: the first of each scheme, in PubChem's order (the primary first). */
    private const SYNONYM_SCHEMES = [Scheme::CAS, Scheme::CHEBI, Scheme::CHEMBL, Scheme::WIKIDATA, Scheme::UNII, Scheme::DSSTOX, Scheme::KEGG];

    /** @var array{string, list<int>}|null the CIDs of the last text searched, for its next pages */
    private ?array $lastSearch = null;

    /**
     * @param int  $synonyms    how many synonyms to keep (0: none, and no call for them)
     * @param bool $description whether to read the description (one call)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly int $synonyms = 20,
        private readonly bool $description = true,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.2,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'pubchem';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::STRUCTURE, Capability::CROSS_REFERENCES];
    }

    public function entity(Identifier|string $id): ?Entity
    {
        $cid = $this->cid($id);
        if (null === $cid) {
            return null;
        }
        $row = $this->properties([$cid], array_keys(self::PROPERTIES))[0] ?? null;
        if (null === $row) {
            return null;
        }
        $synonyms = $this->synonyms > 0 ? ($this->getJson('compound/cid/'.$cid.'/synonyms/JSON')['InformationList']['Information'][0]['Synonym'] ?? []) : [];
        $description = null;
        if ($this->description) {
            foreach ($this->getJson('compound/cid/'.$cid.'/description/JSON')['InformationList']['Information'] ?? [] as $information) {
                if (null !== ($description = self::text($information['Description'] ?? null))) {
                    break;
                }
            }
        }

        return $this->toEntity($row, $synonyms, $description);
    }

    /**
     * Compounds named by the text: those whose name is the text first, then
     * those with a name that holds it as a word (PubChem's name_type=word),
     * each once; read with a few properties only - entity() reads the rest.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::CHEMISTRY !== $query->domain) {
            return new Page();
        }
        $text = trim($query->text);
        if ('' === $text) {
            return new Page();
        }
        if ($this->lastSearch && $this->lastSearch[0] === $text) {
            $cids = $this->lastSearch[1];
        } else {
            $exact = $this->getJson('compound/name/cids/JSON', ['name' => $text])['IdentifierList']['CID'] ?? [];
            $words = $this->getJson('compound/name/cids/JSON', ['name' => $text, 'name_type' => 'word'])['IdentifierList']['CID'] ?? [];
            $cids = array_values(array_unique(array_map('intval', [...$exact, ...$words])));
            $this->lastSearch = [$text, $cids];
        }
        $offset = max(0, (int) $query->cursor);
        $limit = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $slice = \array_slice($cids, $offset, $limit);
        $entities = $slice ? array_map(fn (array $row) => $this->toEntity($row), $this->properties($slice, self::SEARCH_PROPERTIES)) : [];

        return new Page($entities, $offset + $limit < \count($cids) ? (string) ($offset + $limit) : null, \count($cids));
    }

    /**
     * The CID of what is named: a CID read as is; an InChIKey, an InChI, a
     * SMILES looked up as a structure; any other identifier PubChem keeps,
     * and a plain name, looked up as a name (a string with the characters of
     * a SMILES only is tried as one when no name matches). Null when PubChem
     * knows none.
     */
    private function cid(Identifier|string $id): ?int
    {
        if (\is_string($id) && preg_match('~^smiles:\s*(\S+)$~i', trim($id), $m)) {
            return $this->lookup('smiles', $m[1]);
        }
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, self::SCHEMES, true) ? $id : throw NotSupportedException::identifier('pubchem', (string) $id);
        } else {
            $text = trim($id);
            $identifier = Identifier::parse($text) ?? (ctype_digit($text) ? Identifier::tryOf(Scheme::PUBCHEM_CID, $text) : null);
            // An identifier of a scheme PubChem does not read is refused; anything else is a name or a SMILES.
            if (null !== $identifier && !\in_array($identifier->scheme, self::SCHEMES, true)) {
                throw NotSupportedException::identifier('pubchem', $id);
            }
        }
        if (null === $identifier) {
            $smiles = (bool) preg_match('~^[A-Za-z0-9@+\-\[\]()\\\\/%=#$.:*]+$~', $text);
            if ($smiles && preg_match('~[=#()\[\]@/\\\\]~', $text)) {
                return $this->lookup('smiles', $text) ?? $this->lookup('name', $text);
            }

            return $this->lookup('name', $text) ?? ($smiles ? $this->lookup('smiles', $text) : null);
        }

        return match ($identifier->scheme) {
            Scheme::PUBCHEM_CID => (int) $identifier->value,
            Scheme::INCHIKEY => $this->first($this->getJson('compound/inchikey/'.$identifier->value.'/cids/JSON')),
            Scheme::INCHI => $this->lookup('inchi', $identifier->value),
            Scheme::UNII => $this->lookup('name', 'UNII-'.$identifier->value),
            default => $this->lookup('name', $identifier->display()),
        };
    }

    /** The first CID PubChem gives for a name, a SMILES or an InChI; null when none (or a SMILES PubChem cannot read). */
    private function lookup(string $namespace, string $value): ?int
    {
        try {
            return $this->first($this->getJson('compound/'.$namespace.'/cids/JSON', [$namespace => $value]));
        } catch (ProviderException $e) {
            // A string that is not a structure: 400 "Unable to convert input into a compound object".
            if ('name' !== $namespace && 400 === $e->status) {
                return null;
            }
            throw $e;
        }
    }

    private function first(?array $data): ?int
    {
        $cid = $data['IdentifierList']['CID'][0] ?? null;

        return null === $cid || 0 === (int) $cid ? null : (int) $cid;
    }

    /**
     * @param list<int>    $cids
     * @param list<string> $properties
     *
     * @return list<array<string, mixed>> the rows of the compounds that exist, in the order asked
     */
    private function properties(array $cids, array $properties): array
    {
        $rows = $this->getJson('compound/cid/'.implode(',', $cids).'/property/'.implode(',', array_unique(['Title', ...$properties])).'/JSON')['PropertyTable']['Properties'] ?? [];

        // A CID that does not exist comes back alone, without a property.
        return array_values(array_filter($rows, static fn (array $row) => isset($row['Title']) || isset($row['MolecularFormula'])));
    }

    /**
     * @param array<string, mixed> $row      a row of PubChem's PropertyTable
     * @param list<string>         $synonyms
     */
    private function toEntity(array $row, array $synonyms = [], ?string $description = null): Entity
    {
        $cid = (int) $row['CID'];
        $properties = [];
        foreach (self::PROPERTIES as $name => [$key, $label, $unit]) {
            if (!isset($row[$name]) || '' === $row[$name]) {
                continue;
            }
            $value = $row[$name];
            // PubChem writes the masses as strings: "194.19".
            if (\is_string($value) && is_numeric($value) && null !== $unit) {
                $value = (float) $value;
            }
            $properties[] = new Property($key, $label, null !== $unit && (\is_int($value) || \is_float($value)) ? new Quantity($value, $unit) : $value, 'pubchem');
        }

        $identifiers = [
            Identifier::tryOf(Scheme::INCHIKEY, $row['InChIKey'] ?? null),
            Identifier::tryOf(Scheme::INCHI, $row['InChI'] ?? null),
        ];
        $names = [];
        $seen = [];
        foreach ($synonyms as $synonym) {
            $synonym = (string) $synonym;
            $parsed = Identifier::parse($synonym) ?? (preg_match('~^[CD]\d{5}$~', $synonym) ? Identifier::tryOf(Scheme::KEGG, $synonym) : null);
            if (null !== $parsed) {
                if (\in_array($parsed->scheme, self::SYNONYM_SCHEMES, true) && !isset($seen[$parsed->scheme->value])) {
                    $seen[$parsed->scheme->value] = true;
                    $identifiers[] = $parsed;
                }
                continue;
            }
            // Registry numbers and catalogue codes are not names: "NSC 5036", "EINECS 200-362-1", "AI3-20154".
            if (preg_match('~^[A-Z0-9]+(?:[\s:_-]+[A-Z0-9.]+)*$~', $synonym) && preg_match('~\d~', $synonym)) {
                continue;
            }
            $names[] = $synonym;
        }
        $title = (string) ($row['Title'] ?? $row['IUPACName'] ?? 'CID '.$cid);
        $names = array_values(array_filter(array_unique($names), static fn (string $n) => strtolower($n) !== strtolower($title)));
        $unique = [];
        foreach ($names as $name) {
            $unique[strtolower($name)] ??= $name;
        }

        return new Entity(
            id: Identifier::cid($cid),
            label: $title,
            domain: Domain::CHEMISTRY,
            kind: 'compound',
            synonyms: \array_slice(array_values($unique), 0, $this->synonyms),
            description: $description,
            properties: $properties,
            identifiers: new Identifiers($identifiers),
            url: 'https://pubchem.ncbi.nlm.nih.gov/compound/'.$cid,
            image: rtrim($this->baseUri, '/').'/compound/cid/'.$cid.'/PNG',
            source: 'pubchem',
        );
    }
}
