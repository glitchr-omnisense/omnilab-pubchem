<?php

namespace Omnilab\PubChem;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * PubChem: compounds, their computed properties, synonyms, structure and
 * cross-references - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: whom NCBI writes to before it blocks a caller
 *     synonyms: 20                     # how many synonyms to keep (0: none, one call less per compound)
 *     description: true                # false: no description, one call less per compound
 *     throttle: 0.2                    # seconds between two calls: PubChem allows five a second, never set it lower
 *     base_uri: 'https://pubchem.ncbi.nlm.nih.gov/rest/pug/'
 */
final class PubChemSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'pubchem',
            'omnilab.required_options' => [],
            'synonyms' => 20,
            'description' => true,
            'throttle' => 0.2,
            'base_uri' => PubChemSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new PubChemSource(
            $this->http ?? HttpClient::create(),
            (int) $c->get('synonyms'),
            (bool) $c->get('description'),
            (string) $c->get('base_uri', PubChemSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
