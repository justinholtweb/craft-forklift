<?php

declare(strict_types=1);

namespace justinholtweb\forklift\migrations;

use craft\commerce\db\Table as CommerceTable;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\forklift\db\Table;

/**
 * Forklift's schema.
 *
 * Two element-backed tables (companies, quotes) and ten plain ones. Money columns are
 * `decimal(14,4)` throughout, matching Commerce's own, so a total copied from an order into the
 * credit ledger cannot round on the way.
 *
 * Foreign keys are deliberate about their delete behaviour:
 *
 * - Rows that only exist *because of* a parent cascade — members with their company, entries with
 *   their price list, quote lines with their quote.
 * - Rows that are a *record of something that happened* null their reference instead, so deleting
 *   a price list does not erase the invoice it priced.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Reverse order so a foreign key never outlives the table it points at.
        $this->dropTableIfExists(Table::ORDERS);
        $this->dropTableIfExists(Table::CREDIT_ENTRIES);
        $this->dropTableIfExists(Table::APPROVALS);
        $this->dropTableIfExists(Table::QUOTELINES);
        $this->dropTableIfExists(Table::QUOTES);
        $this->dropTableIfExists(Table::CERTIFICATES);
        $this->dropTableIfExists(Table::PRICELIST_COMPANIES);
        $this->dropTableIfExists(Table::PRICELIST_ENTRIES);
        $this->dropTableIfExists(Table::PRICELISTS);
        $this->dropTableIfExists(Table::MEMBERS);
        $this->dropTableIfExists(Table::COMPANIES);
        $this->dropTableIfExists(Table::TERMS);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::TERMS, [
            'id' => $this->primaryKey(),
            'storeId' => $this->integer(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string(64)->notNull(),
            'netDays' => $this->integer()->notNull()->defaultValue(30),
            // The early-settlement half of "2/10 Net 30". Null means there is no early discount.
            'discountPercent' => $this->decimal(6, 4),
            'discountDays' => $this->integer(),
            'description' => $this->text(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::COMPANIES, [
            'id' => $this->integer()->notNull(),
            // The customer's account code in the merchant's own ERP. Not a slug — the element's
            // slug is Craft's, this is the number the warehouse writes on the pick note.
            'code' => $this->string(64),
            'accountStatus' => $this->string(20)->notNull()->defaultValue('active'),
            'ownerId' => $this->integer(),
            'termsId' => $this->integer(),
            'creditLimit' => $this->decimal(14, 4),
            'creditEnabled' => $this->boolean()->notNull()->defaultValue(false),
            'requiresPoNumber' => $this->boolean()->notNull()->defaultValue(false),
            // Orders at or above this need somebody to sign them off. Null means no threshold,
            // which is different from zero — zero means every order needs approval.
            'approvalThreshold' => $this->decimal(14, 4),
            'taxExempt' => $this->boolean()->notNull()->defaultValue(false),
            'phone' => $this->string(64),
            'website' => $this->string(),
            'taxId' => $this->string(64),
            'notes' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable(Table::MEMBERS, [
            'id' => $this->primaryKey(),
            'companyId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'role' => $this->string(20)->notNull()->defaultValue('buyer'),
            // Per *order*, not per month. A monthly budget needs a period to reset against and a
            // reconciliation when an order is refunded; a per-order ceiling is the thing
            // purchasing departments actually agree on and the thing a buyer can act on.
            'spendLimit' => $this->decimal(14, 4),
            'requiresApproval' => $this->boolean()->notNull()->defaultValue(false),
            'isDefault' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::PRICELISTS, [
            'id' => $this->primaryKey(),
            'storeId' => $this->integer(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string(64)->notNull(),
            'description' => $this->text(),
            // Highest priority wins when two lists both price a purchasable. Ties break on id,
            // so the answer is stable rather than "whichever the database felt like".
            'priority' => $this->integer()->notNull()->defaultValue(0),
            'allCompanies' => $this->boolean()->notNull()->defaultValue(false),
            'dateFrom' => $this->dateTime(),
            'dateTo' => $this->dateTime(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::PRICELIST_ENTRIES, [
            'id' => $this->primaryKey(),
            'priceListId' => $this->integer()->notNull(),
            // purchasable | product | purchasableType | all
            'targetType' => $this->string(32)->notNull()->defaultValue('purchasable'),
            'targetId' => $this->integer(),
            // Kept alongside targetId so an import by SKU can be re-resolved after a product is
            // rebuilt, and so a CP row still reads meaningfully when its purchasable is gone.
            'sku' => $this->string(255),
            'minQty' => $this->integer()->notNull()->defaultValue(1),
            // fixed | percentOff | amountOff
            'priceType' => $this->string(20)->notNull()->defaultValue('fixed'),
            'amount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::PRICELIST_COMPANIES, [
            'id' => $this->primaryKey(),
            'priceListId' => $this->integer()->notNull(),
            'companyId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CERTIFICATES, [
            'id' => $this->primaryKey(),
            'companyId' => $this->integer()->notNull(),
            'name' => $this->string()->notNull(),
            'certificateNumber' => $this->string(128),
            // Where the exemption is good for. Null country means everywhere, which is what a
            // charity registration or an inter-company account looks like.
            'countryCode' => $this->string(10),
            'administrativeArea' => $this->string(64),
            'issueDate' => $this->dateTime(),
            'expiryDate' => $this->dateTime(),
            'assetId' => $this->integer(),
            // pending | approved | rejected
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'note' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::QUOTES, [
            'id' => $this->integer()->notNull(),
            'number' => $this->string(32)->notNull(),
            'storeId' => $this->integer(),
            'companyId' => $this->integer(),
            'requesterId' => $this->integer(),
            'email' => $this->string(),
            // requested | pricing | sent | accepted | declined | expired | cancelled
            'status' => $this->string(20)->notNull()->defaultValue('requested'),
            'currency' => $this->string(3),
            'reference' => $this->string(64),
            'message' => $this->text(),
            'internalNote' => $this->text(),
            'shippingCost' => $this->decimal(14, 4),
            'discount' => $this->decimal(14, 4),
            'expiryDate' => $this->dateTime(),
            'sentDate' => $this->dateTime(),
            'respondedDate' => $this->dateTime(),
            'orderId' => $this->integer(),
            // The incomplete Commerce cart the quote was materialised into. It is that cart, not
            // the quote, that the pay-by-link loads.
            'cartNumber' => $this->string(32),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable(Table::QUOTELINES, [
            'id' => $this->primaryKey(),
            'quoteId' => $this->integer()->notNull(),
            'purchasableId' => $this->integer(),
            'sku' => $this->string(255),
            'description' => $this->string(),
            'qty' => $this->integer()->notNull()->defaultValue(1),
            // What it would have cost without the quote, kept so the buyer can see the saving and
            // so a merchant can tell a discount from a typo six months later.
            'listPrice' => $this->decimal(14, 4),
            'price' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'note' => $this->text(),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::APPROVALS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'companyId' => $this->integer()->notNull(),
            'requesterId' => $this->integer(),
            'approverId' => $this->integer(),
            // pending | approved | declined | cancelled | expired
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'amount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'currency' => $this->string(3),
            // Why it needed approving, recorded at request time. The company's threshold can
            // change afterwards, and the audit trail should not change with it.
            'reason' => $this->string(255),
            'note' => $this->text(),
            'decisionNote' => $this->text(),
            // Lets an approver decide from an email without signing in. 32 characters, generated
            // with randomString — not a UUID, which is 36 and would be truncated.
            'token' => $this->char(32),
            'requestedDate' => $this->dateTime()->notNull(),
            'decisionDate' => $this->dateTime(),
            'expiryDate' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CREDIT_ENTRIES, [
            'id' => $this->primaryKey(),
            'companyId' => $this->integer()->notNull(),
            // charge | payment | adjustment
            'type' => $this->string(20)->notNull(),
            'orderId' => $this->integer(),
            // Signed. A charge increases what is owed, a payment decreases it. Storing the sign
            // rather than deriving it from the type means the balance is a SUM and nothing else.
            'amount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'currency' => $this->string(3),
            'reference' => $this->string(128),
            'note' => $this->text(),
            'entryDate' => $this->dateTime()->notNull(),
            'dueDate' => $this->dateTime(),
            'createdBy' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::ORDERS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'companyId' => $this->integer(),
            'memberId' => $this->integer(),
            'poNumber' => $this->string(128),
            'termsId' => $this->integer(),
            'dueDate' => $this->dateTime(),
            'approvalId' => $this->integer(),
            // True when this order would have needed approval but the licence had lapsed. The one
            // place Forklift fails open, and it leaves a mark rather than staying quiet.
            'approvalBypassed' => $this->boolean()->notNull()->defaultValue(false),
            'quoteId' => $this->integer(),
            // Which price lists priced this order, as a JSON array of ids. A snapshot for the
            // audit trail — never read back to compute anything.
            'priceListIds' => $this->text(),
            'taxExemptCertificateId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::TERMS, ['handle', 'storeId'], true);
        $this->createIndex(null, Table::TERMS, ['enabled']);

        $this->createIndex(null, Table::COMPANIES, ['code']);
        $this->createIndex(null, Table::COMPANIES, ['accountStatus']);
        $this->createIndex(null, Table::COMPANIES, ['termsId']);

        // One membership per person per company. The role and the limit live on the row, so a
        // second row would be two answers to the same question.
        $this->createIndex(null, Table::MEMBERS, ['companyId', 'userId'], true);
        $this->createIndex(null, Table::MEMBERS, ['userId']);

        $this->createIndex(null, Table::PRICELISTS, ['handle', 'storeId'], true);
        $this->createIndex(null, Table::PRICELISTS, ['enabled', 'priority']);

        $this->createIndex(null, Table::PRICELIST_ENTRIES, ['priceListId', 'targetType', 'targetId', 'minQty']);
        $this->createIndex(null, Table::PRICELIST_ENTRIES, ['sku']);

        $this->createIndex(null, Table::PRICELIST_COMPANIES, ['priceListId', 'companyId'], true);
        $this->createIndex(null, Table::PRICELIST_COMPANIES, ['companyId']);

        $this->createIndex(null, Table::CERTIFICATES, ['companyId', 'status']);
        $this->createIndex(null, Table::CERTIFICATES, ['expiryDate']);

        $this->createIndex(null, Table::QUOTES, ['number'], true);
        $this->createIndex(null, Table::QUOTES, ['companyId', 'status']);
        $this->createIndex(null, Table::QUOTES, ['status', 'expiryDate']);

        $this->createIndex(null, Table::QUOTELINES, ['quoteId', 'sortOrder']);

        // One live approval per order. A second pending row for the same order is two people
        // being asked the same question and two answers arriving.
        $this->createIndex(null, Table::APPROVALS, ['orderId', 'status']);
        $this->createIndex(null, Table::APPROVALS, ['companyId', 'status']);
        $this->createIndex(null, Table::APPROVALS, ['token'], true);

        $this->createIndex(null, Table::CREDIT_ENTRIES, ['companyId', 'entryDate']);
        $this->createIndex(null, Table::CREDIT_ENTRIES, ['orderId']);
        $this->createIndex(null, Table::CREDIT_ENTRIES, ['companyId', 'dueDate']);

        $this->createIndex(null, Table::ORDERS, ['orderId'], true);
        $this->createIndex(null, Table::ORDERS, ['companyId']);
        $this->createIndex(null, Table::ORDERS, ['quoteId']);
    }

    private function addForeignKeys(): void
    {
        // Element-backed tables share the element's life.
        $this->addForeignKey(null, Table::COMPANIES, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::COMPANIES, ['ownerId'], CraftTable::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::COMPANIES, ['termsId'], Table::TERMS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::MEMBERS, ['companyId'], Table::COMPANIES, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::MEMBERS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE');

        $this->addForeignKey(null, Table::PRICELIST_ENTRIES, ['priceListId'], Table::PRICELISTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::PRICELIST_COMPANIES, ['priceListId'], Table::PRICELISTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::PRICELIST_COMPANIES, ['companyId'], Table::COMPANIES, ['id'], 'CASCADE');

        $this->addForeignKey(null, Table::CERTIFICATES, ['companyId'], Table::COMPANIES, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::CERTIFICATES, ['assetId'], CraftTable::ELEMENTS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::QUOTES, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::QUOTES, ['companyId'], Table::COMPANIES, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::QUOTES, ['requesterId'], CraftTable::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::QUOTES, ['orderId'], CommerceTable::ORDERS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::QUOTELINES, ['quoteId'], Table::QUOTES, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::QUOTELINES, ['purchasableId'], CraftTable::ELEMENTS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::APPROVALS, ['orderId'], CommerceTable::ORDERS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::APPROVALS, ['companyId'], Table::COMPANIES, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::APPROVALS, ['requesterId'], CraftTable::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::APPROVALS, ['approverId'], CraftTable::USERS, ['id'], 'SET NULL');

        // Deleting a company deletes its ledger; deleting an *order* must not, or a refunded
        // order takes the payment that settled it with it.
        $this->addForeignKey(null, Table::CREDIT_ENTRIES, ['companyId'], Table::COMPANIES, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::CREDIT_ENTRIES, ['orderId'], CommerceTable::ORDERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::CREDIT_ENTRIES, ['createdBy'], CraftTable::USERS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::ORDERS, ['orderId'], CommerceTable::ORDERS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::ORDERS, ['companyId'], Table::COMPANIES, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['memberId'], Table::MEMBERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['termsId'], Table::TERMS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['approvalId'], Table::APPROVALS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['quoteId'], Table::QUOTES, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['taxExemptCertificateId'], Table::CERTIFICATES, ['id'], 'SET NULL');
    }
}
