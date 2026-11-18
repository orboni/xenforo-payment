<?php

/**
 * Orboni for XenForo.
 *
 * @author    Orboni <support@orboni.com>
 * @copyright 2026 Orboni
 * @license   MIT
 */

namespace Orboni\Payment;

use Orboni\Payment\Payment\Orboni;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Create;
use XF\Entity\PaymentProvider;

/**
 * Installs the payment provider "orboni" (Setup → Payment profiles → Add
 * payment profile) and its table. Uninstalling removes both; payment
 * profiles made with it stay, hidden, until it is installed again.
 */
class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUninstallTrait;
    use StepRunnerUpgradeTrait;

    public function installStep1()
    {
        $this->schemaManager()->createTable('xf_orboni_payment', function (Create $table) {
            $table->addColumn('request_key', 'varbinary', 32);
            $table->addColumn('session_id', 'varbinary', 100);
            $table->addColumn('payment_profile_id', 'int')->unsigned();
            $table->addColumn('created_date', 'int')->unsigned()->setDefault(0);
            $table->addColumn('expires_date', 'int')->unsigned()->setDefault(0);
            $table->addColumn('checked_date', 'int')->unsigned()->setDefault(0);
            $table->addColumn('state', 'varbinary', 50)->setDefault('');
            $table->addColumn('granted', 'tinyint')->unsigned()->setDefault(0);
            $table->addColumn('changes', 'smallint')->unsigned()->setDefault(0);
            $table->addColumn('return_url', 'text');
            $table->addPrimaryKey('request_key');
            $table->addKey('session_id');
            $table->addKey(['granted', 'checked_date']);
        });
    }

    public function installStep2()
    {
        $provider = \XF::em()->find(PaymentProvider::class, Orboni::PROVIDER_ID);
        if (! $provider) {
            $provider = \XF::em()->create(PaymentProvider::class);
            $provider->provider_id = Orboni::PROVIDER_ID;
        }
        $provider->provider_class = 'Orboni\Payment:Orboni';
        $provider->addon_id = 'Orboni/Payment';
        $provider->save();
    }

    public function uninstallStep1()
    {
        $provider = \XF::em()->find(PaymentProvider::class, Orboni::PROVIDER_ID);
        if ($provider) {
            $provider->delete();
        }
    }

    public function uninstallStep2()
    {
        $this->schemaManager()->dropTable('xf_orboni_payment');
    }
}
