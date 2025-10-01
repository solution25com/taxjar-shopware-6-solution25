<?php declare(strict_types=1);

namespace Shopware\Core\System\SystemConfig\Validation;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SystemConfig\Exception\BundleConfigNotFoundException;
use Shopware\Core\System\SystemConfig\Service\ConfigurationService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @internal
 */
#[Package('framework')]
class SystemConfigValidator
{
    /**
     * @internal
     */
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly DataValidator $validator
    ) {
    }

    /**
     * @param array<string, mixed> $inputData
     *
     * @throws ConstraintViolationException
     */
    public function validate(array $inputData, Context $context): void
    {
        $definition = new DataValidationDefinition('systemConfig.update');

        /**
         * @var array<string, mixed> $inputValues
         */
        foreach ($inputData as $saleChannelId => $inputValues) {
            // If sales channel is defined, nulls are valid values, as they are used to remove custom values in child configuration
            $allowNulls = $saleChannelId !== 'null';

            /** @var string[] $allKeys */
            $allKeys = array_keys($inputValues);

            $domains = array_map(fn (string $key) => implode('.', explode('.', $key, -1)), $allKeys);
            $domains = array_unique($domains);

            $subDefinition = new DataValidationDefinition('systemConfig.update.' . $saleChannelId);

            foreach ($domains as $domain) {
                $formConfig = $this->getSystemConfigByDomain($domain, $context);
                $constraints = $this->prepareValidationConstraints($formConfig, $allKeys, $allowNulls);

                foreach ($constraints as $elementName => $elementConstraints) {
                    $subDefinition->add($elementName, ...$elementConstraints);
                }
            }

            if (empty($subDefinition->getProperties())) {
                continue;
            }

            $definition->addSub($saleChannelId, $subDefinition);
        }

        $this->validator->validate($inputData, $definition);
    }

    /**
     * @param array<string, mixed> $formConfig
     * @param array<string> $inputConfigKeys
     *
     * @return array<string, Constraint[]>
     */
    private function prepareValidationConstraints(array $formConfig, array $inputConfigKeys, bool $allowNulls): array
    {
        /** @var array<string, Constraint[]> $constraints */
        $constraints = [];

        foreach ($formConfig as $card) {
            $elements = $card['elements'] ?? [];

            foreach ($elements as $element) {
                if (!\in_array($element['name'], $inputConfigKeys, true)) {
                    continue;
                }

                $elementConfig = $element['config'];

                $constraints[$element['name']] = $this->buildConstraintsWithConfigs($elementConfig, $allowNulls);
            }
        }

        return $constraints;
    }

    /**
     * @param array<string, mixed> $elementConfig
     *
     * @return array<int, Constraint>
     */
    private function buildConstraintsWithConfigs(array $elementConfig, bool $allowNulls): array
    {
        /** @var array<string, callable(mixed): Constraint> $constraints */
        $constraints = [
            'minLength' => fn (mixed $ruleValue) => new Assert\Length(min: $ruleValue === null ? null : max(0, (int) $ruleValue)),
            'maxLength' => fn (mixed $ruleValue) => new Assert\Length(max: $ruleValue === null ? null : max(1, (int) $ruleValue)),
            'min' => fn (mixed $ruleValue) => new Assert\Range(min: $ruleValue),
            'max' => fn (mixed $ruleValue) => new Assert\Range(max: $ruleValue),
            'dataType' => fn (mixed $ruleValue) => new Assert\Type($ruleValue),
            'required' => fn (mixed $ruleValue) => new Assert\NotBlank(null, null, $allowNulls),
        ];

        $constraintsResult = [];

        foreach ($constraints as $ruleName => $constraint) {
            if (!\array_key_exists($ruleName, $elementConfig)) {
                continue;
            }

            $ruleValue = $elementConfig[$ruleName];

            $constraintsResult[] = $constraint($ruleValue);
        }

        return $constraintsResult;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSystemConfigByDomain(string $domain, Context $context): array
    {
        try {
            return $this->configurationService->getConfiguration($domain, $context);
        } catch (BundleConfigNotFoundException) {
            return [];
        }
    }
}
