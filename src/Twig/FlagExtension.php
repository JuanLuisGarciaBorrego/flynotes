<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Intl\Countries;
use Twig\Attribute\AsTwigFunction;

final readonly class FlagExtension
{
    public function __construct(private AssetMapperInterface $assetMapper)
    {
    }

    /**
     * Public URL of every country's flag, as JSON. Asset URLs carry a digest that scripts cannot guess,
     * so the pages that draw flags hand this map to assets/js/stamp.js.
     */
    #[AsTwigFunction('flag_urls_json')]
    public function flagUrlsJson(): string
    {
        $urls = [];
        foreach (Countries::getCountryCodes() as $code) {
            $url = $this->assetMapper->getPublicPath(\sprintf('images/flags/%s.svg', strtolower($code)));
            if (null !== $url) {
                $urls[$code] = $url;
            }
        }

        return json_encode($urls, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_UNESCAPED_SLASHES);
    }
}
