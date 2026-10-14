<?php

namespace Omnilab\PubChem\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\PubChem\PubChemSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from pubchem.ncbi.nlm.nih.gov/rest/pug on 2026-10-15
 * (caffeine, CID 2519): name-caffeine.json (compound/name/cids?name=caffeine),
 * properties-2519.json (compound/cid/2519/property/...), synonyms-2519.json
 * (its synonyms, cut from 413 to 59, the identifiers of other bases kept),
 * description-2519.json, search-word-caffeine.json (name_type=word, cut
 * from 230 CIDs to 12), search-properties.json (the first three, few
 * properties), notfound.json (a name PubChem does not know: 404),
 * properties-missing.json (a CID that does not exist: a row without
 * properties).
 */
final class PubChemSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = ['throttle' => 0]): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = rawurldecode(substr($path, \strlen('/rest/pug/'))).($query ? '?'.http_build_query($query) : '');
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                str_ends_with($path, '/compound/name/cids/JSON') && 'caffeine' === ($query['name'] ?? null) => isset($query['name_type']) ? 'search-word-caffeine.json' : 'name-caffeine.json',
                str_ends_with($path, '/compound/name/cids/JSON') && \in_array($query['name'] ?? null, ['58-08-2', 'CHEBI:27732', 'Q60235', 'UNII-3G6A5W338E'], true) => 'name-caffeine.json',
                str_ends_with($path, '/compound/smiles/cids/JSON') && 'CN1C=NC2=C1C(=O)N(C(=O)N2C)C' === ($query['smiles'] ?? null) => 'name-caffeine.json',
                str_ends_with($path, '/compound/inchikey/RYYVLZVUVIJVGH-UHFFFAOYSA-N/cids/JSON') => 'name-caffeine.json',
                (bool) preg_match('~/compound/cid/2519/property/Title,MolecularFormula,MolecularWeight,ExactMass,~', $path) => 'properties-2519.json',
                str_ends_with($path, '/compound/cid/2519,9871508,56841593/property/Title,MolecularFormula,MolecularWeight,InChIKey,IUPACName/JSON') => 'search-properties.json',
                str_ends_with($path, '/compound/cid/2519/synonyms/JSON') => 'synonyms-2519.json',
                str_ends_with($path, '/compound/cid/2519/description/JSON') => 'description-2519.json',
                str_contains($path, '/compound/cid/1999999999/property/') => 'properties-missing.json',
                default => null,
            };

            return $fixture
                ? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture))
                : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/notfound.json'), ['http_code' => 404]);
        });

        return (new PubChemSourceFactory($http))->create($options);
    }

    public function testCaffeineByNameWithItsPropertiesAndTheirUnits(): void
    {
        $caffeine = $this->source()->entity('caffeine');

        self::assertSame('Caffeine', $caffeine->label);
        self::assertSame(Domain::CHEMISTRY, $caffeine->domain);
        self::assertSame('compound', $caffeine->kind);
        self::assertSame('pubchem.cid:2519', $caffeine->id->key());
        self::assertSame('C8H10N4O2', $caffeine->value('molecular_formula'));
        self::assertEquals(new Quantity(194.19, 'g/mol'), $caffeine->value('molecular_weight'));
        self::assertSame('194.19 g/mol', (string) $caffeine->property('molecular_weight'));
        self::assertEquals(new Quantity(58.4, 'Å²'), $caffeine->value('tpsa'));
        self::assertEqualsWithDelta(194.08037557, $caffeine->property('exact_mass')->number(), 1e-9);
        self::assertSame('Da', $caffeine->property('monoisotopic_mass')->unit());
        self::assertSame(-0.1, $caffeine->value('xlogp'));
        self::assertSame(0, $caffeine->value('h_bond_donors'));
        self::assertSame(3, $caffeine->value('h_bond_acceptors'));
        self::assertSame('1,3,7-trimethylpurine-2,6-dione', $caffeine->value('iupac_name'));
        self::assertSame('CN1C=NC2=C1C(=O)N(C(=O)N2C)C', $caffeine->value('smiles'));
        self::assertSame('pubchem', $caffeine->property('tpsa')->source);
        self::assertSame(['compound/name/cids/JSON?name=caffeine', 'compound/cid/2519/property/Title,MolecularFormula,MolecularWeight,ExactMass,MonoisotopicMass,IUPACName,SMILES,ConnectivitySMILES,InChI,InChIKey,XLogP,TPSA,Complexity,Charge,HBondDonorCount,HBondAcceptorCount,RotatableBondCount,HeavyAtomCount/JSON', 'compound/cid/2519/synonyms/JSON', 'compound/cid/2519/description/JSON'], $this->calls, 'four calls: the CID, the properties, the synonyms, the description');
    }

    public function testItsCrossReferencesSynonymsDescriptionAndStructure(): void
    {
        $caffeine = $this->source()->entity('caffeine');

        self::assertSame('RYYVLZVUVIJVGH-UHFFFAOYSA-N', $caffeine->identifier(Scheme::INCHIKEY));
        self::assertSame('58-08-2', $caffeine->identifier(Scheme::CAS), 'the first CAS number, not the deprecated 114303-55-8');
        self::assertSame('27732', $caffeine->identifier(Scheme::CHEBI), 'the first ChEBI id');
        self::assertSame('CHEMBL113', $caffeine->identifier(Scheme::CHEMBL));
        self::assertSame('Q60235', $caffeine->identifier(Scheme::WIKIDATA));
        self::assertSame('3G6A5W338E', $caffeine->identifier(Scheme::UNII));
        self::assertSame('DTXSID0020232', $caffeine->identifier(Scheme::DSSTOX));
        self::assertSame('C07481', $caffeine->identifier(Scheme::KEGG));
        self::assertSame(['inchikey', 'inchi', 'cas', 'dsstox', 'chebi', 'chembl', 'unii', 'kegg', 'wikidata'], array_map(static fn (Identifier $i) => $i->scheme->value, $caffeine->identifiers->all()));

        self::assertCount(20, $caffeine->synonyms);
        self::assertSame('Guaranine', $caffeine->synonyms[0]);
        self::assertContains('1,3,7-Trimethylxanthine', $caffeine->synonyms);
        self::assertNotContains('caffeine', $caffeine->synonyms, 'the label, whatever its case, is not a synonym');
        foreach (['NSC-5036', 'HSDB 36', 'EINECS 200-362-1', 'CHEBI:27732', '58-08-2'] as $code) {
            self::assertNotContains($code, $caffeine->synonyms, 'registry numbers and identifiers are not names');
        }

        self::assertStringStartsWith('Caffeine is a trimethylxanthine', $caffeine->description);
        self::assertSame('https://pubchem.ncbi.nlm.nih.gov/compound/2519', $caffeine->url);
        self::assertSame('https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/2519/PNG', $caffeine->image);
        self::assertContains(Capability::STRUCTURE, $this->source()->capabilities());
    }

    public function testEveryWayOfNamingTheCompound(): void
    {
        $source = $this->source();
        foreach (['2519', 'CID 2519', Identifier::cid(2519), 'https://pubchem.ncbi.nlm.nih.gov/compound/2519', 'RYYVLZVUVIJVGH-UHFFFAOYSA-N', '58-08-2', 'CHEBI:27732', 'Q60235', 'UNII-3G6A5W338E', 'CN1C=NC2=C1C(=O)N(C(=O)N2C)C', 'smiles:CN1C=NC2=C1C(=O)N(C(=O)N2C)C'] as $id) {
            self::assertSame('Caffeine', $source->entity($id)?->label, 'by '.$id);
        }
        $this->calls = [];
        $source->entity('CHEBI:27732');
        self::assertSame('compound/name/cids/JSON?name=CHEBI%3A27732', $this->calls[0], 'a ChEBI id is one of PubChem\'s synonyms: looked up as a name');
    }

    public function testFewerCallsWhenAskedLess(): void
    {
        $caffeine = $this->source(null, ['throttle' => 0, 'synonyms' => 0, 'description' => false])->entity(Identifier::cid(2519));

        self::assertCount(1, $this->calls, 'the properties only');
        self::assertSame([], $caffeine->synonyms);
        self::assertNull($caffeine->description);
        self::assertSame('RYYVLZVUVIJVGH-UHFFFAOYSA-N', $caffeine->identifier(Scheme::INCHIKEY), 'the InChIKey comes with the properties');
    }

    public function testUnknownIsNull(): void
    {
        self::assertNull($this->source()->entity('nonexistentcompoundxyz'));
        self::assertNull($this->source()->entity(Identifier::cid(1999999999)), 'a CID PubChem answers without properties');
    }

    public function testAnIdentifierOfAnotherDomainIsRefusedBeforeAnyCall(): void
    {
        try {
            $this->source()->entity(Identifier::of(Scheme::UNIPROT, 'P69905'));
            self::fail('UniProt is not read');
        } catch (NotSupportedException) {
        }
        try {
            $this->source()->entity('ENSG00000141510');
            self::fail('Ensembl is not read');
        } catch (NotSupportedException) {
        }
        self::assertSame([], $this->calls);
    }

    public function testSearchGivesTheExactNameFirstThenTheWordsPageByPage(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('caffeine', limit: 3));

        self::assertSame(['Caffeine', 'Acetaminophen; Aspirin; Caffeine', 'Acetaminophen; Butalbital; Caffeine; Codeine Phosphate'], array_map(static fn ($e) => $e->label, $page->items));
        self::assertSame(12, $page->total, 'the exact match once, among the twelve words recorded');
        self::assertSame('3', $page->next);
        self::assertEquals(new Quantity(525.5, 'g/mol'), $page->items[1]->value('molecular_weight'));
        self::assertSame('BKMBGNWZSQNIKU-UHFFFAOYSA-N', $page->items[1]->identifier(Scheme::INCHIKEY));
        self::assertCount(3, $this->calls);

        self::assertSame([], $source->search(new Query('caffeine', Domain::ASTRONOMY))->items, 'nothing of another domain');
        self::assertSame(3, \count($this->calls), 'and no call for it');
    }

    public function testErrorsMapTheSameWayAsEverySource(): void
    {
        try {
            $this->source(new MockResponse('{"Fault": {"Code": "PUGREST.ServerBusy", "Message": "Too many requests or server too busy"}}', ['http_code' => 503]))->entity('caffeine');
            self::fail('a 503 is an UnavailableException');
        } catch (UnavailableException $e) {
            self::assertSame(503, $e->status);
        }
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '60']]))->entity('caffeine');
    }

    public function testNoSeriesNorProducts(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->source()->series(new SeriesQuery(['temperature_2m'], new Point(48.57, 7.75)));
    }

    public function testTheThrottleLeavesAFifthOfASecondBetweenTwoCalls(): void
    {
        $source = $this->source(null, []);
        $start = microtime(true);
        $source->entity(Identifier::cid(2519));

        self::assertCount(3, $this->calls);
        self::assertGreaterThanOrEqual(0.39, microtime(true) - $start, 'three calls, two intervals of 0.2 s');
    }
}
