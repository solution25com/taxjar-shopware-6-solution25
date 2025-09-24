<?php
/**
 * See COPYING.txt for license details.

 */
declare(strict_types=1);

namespace solu1TaxJar\Core\Content\TaxLog;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class TaxLogDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'itg_taxjar_log';

    /**
     * @return string
     */
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    /**
     * @return string
     */
    public function getEntityClass(): string
    {
        return TaxLogEntity::class;
    }

    /**
     * @return string
     */
    public function getCollectionClass(): string
    {
        return TaxLogCollection::class;
    }

    /**
     * @return FieldCollection
     */
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection(
            [
                (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
                (new StringField('customer_name', 'customerName'))->addFlags(new ApiAware()),
                (new StringField('remote_ip', 'remoteIp'))->addFlags(new ApiAware()),
                (new StringField('customer_email', 'customerEmail'))->addFlags(new ApiAware()),
                (new LongTextField('request_key', 'requestKey'))->addFlags(new ApiAware()),
                (new StringField('type', 'type'))->addFlags(new ApiAware()),
                (new StringField('order_number', 'orderNumber'))->addFlags(new ApiAware()),
                (new StringField('order_id', 'orderId'))->addFlags(new ApiAware()),
                (new LongTextField('request', 'request'))->addFlags(new ApiAware()),
                (new LongTextField('response', 'response'))->addFlags(new ApiAware()),
                (new CreatedAtField()),
                (new UpdatedAtField()),
            ]);
    }
}
