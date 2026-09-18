<?php


namespace SCHEME_LAYER;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

class FlowValidator
{
    private array $rules;

    public function __construct(array $schemeConfig)
    {
        $this->rules = $schemeConfig;
    }

    public function validate(string $fromType, string $toType): void
    {
        $flow = strtolower($fromType . '_to_' . $toType);

        if (!in_array($fromType, $this->rules['asset_categories'])) {
            throw new \Exception("Unsupported source asset type: {$fromType}");
        }

        if (!in_array($toType, $this->rules['asset_categories'])) {
            throw new \Exception("Unsupported destination asset type: {$toType}");
        }

        if (!in_array($flow, $this->rules['all_supported_flows'])) {
            throw new \Exception("Flow {$flow} not permitted by scheme rules");
        }
    }
}

