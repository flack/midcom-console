<?php
/**
 * @package midcom.console
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace midcom\console;

use Symfony\Component\Console\Application as base_application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use midcom\console\command\exec;
use midcom\console\command\purgedeleted;
use midcom\console\command\blobdircleanup;
use midcom\console\command\repligard;

/**
 * OpenPSA CLI command runner
 *
 * @package midcom.console
 */
class application extends base_application
{
    /**
     * The backend type (midgard1, midgard2, midgard-portable)
     *
     * @var string
     */
    protected $backend;

    /**
     * @inheritDoc
     */
    public function __construct($name = 'midcom\console', $version = '9.0beta5+git')
    {
        parent::__construct($name, $version);

        $this->detect_backend();

        $this->getDefinition()
            ->addOption(new InputOption('--config', '-c', InputOption::VALUE_REQUIRED, 'Config name (mgd2 only)'));
        $this->getDefinition()
            ->addOption(new InputOption('--servername', '-s', InputOption::VALUE_REQUIRED, 'HTTP server name', 'localhost'));
        $this->getDefinition()
            ->addOption(new InputOption('--port', '-p', InputOption::VALUE_REQUIRED, 'HTTP server port', '80'));

        $this->_prepare_environment();
        $this->_add_default_commands();
    }

    private function detect_backend()
    {
        if (extension_loaded('midgard'))
        {
            $this->backend = 'midgard';
        }
        else if (extension_loaded('midgard2'))
        {
            $this->backend = 'midgard2';
        }
        else
        {
            $this->backend = 'midgard-portable';
        }
    }

    /**
     * @inheritDoc
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $_SERVER['SERVER_NAME'] = $input->getParameterOption(array('--servername', '-s'), null);
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'];
        $_SERVER['SERVER_PORT'] = $input->getParameterOption(array('--port', '-p'), null);
        $_SERVER['REMOTE_PORT'] = $_SERVER['SERVER_PORT'];

        if ($_SERVER['SERVER_PORT'] == 443)
        {
            $_SERVER['HTTPS'] = 'on';
        }

        if ($this->backend == 'midard2')
        {
            $config_name = $input->getParameterOption(array('--config', '-c'), null);
            if (!\midcom_connection::setup(OPENPSA_PROJECT_BASEDIR, $config_name))
            {
                throw new \RuntimeException('Could not open midgard connection ' . $config_name . ': ' . \midcom_connection::get_error_string());
            }
            if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity())
            {
                $config = \midgard_connection::get_instance()->config;
                $output->writeln('Using <comment>' . $config->dbtype . '</comment> database <info>' . $config->database . '</info>');
            }
        }

        // This makes sure that existing auth and cache instances get overridden
        \midcom::init();

        parent::doRun($input, $output);
    }

    private function _prepare_environment()
    {
        // under midgard-portable, we need the to register the mgdschema classes before starting midcom,
        // under mgd1 some methods return fatal errors when called without connection, so we set this up here:
        if (   $this->backend !== 'midgard2'
            && !\midcom_connection::setup(OPENPSA_PROJECT_BASEDIR))
        {
            throw new \RuntimeException('Could not open midgard connection: ' . \midcom_connection::get_error_string());
        }

        if (file_exists(OPENPSA_PROJECT_BASEDIR . 'config.inc.php'))
        {
            include_once OPENPSA_PROJECT_BASEDIR . 'config.inc.php';
        }

        $GLOBALS['midcom_config_local']['cache_module_content_uncached'] = true;
        if (!defined('MIDCOM_ROOT'))
        {
            if (file_exists(OPENPSA_PROJECT_BASEDIR . 'lib/midcom.php'))
            {
                define('MIDCOM_ROOT', OPENPSA_PROJECT_BASEDIR . 'lib');
            }
            else if (file_exists(OPENPSA_PROJECT_BASEDIR . 'vendor/openpsa/midcom/lib/midcom.php'))
            {
                define('MIDCOM_ROOT', OPENPSA_PROJECT_BASEDIR . 'vendor/openpsa/midcom/lib');
            }
            else
            {
                throw new \Exception('Could not find midcom root');
            }
        }

        if (!defined('OPENPSA2_PREFIX'))
        {
            define('OPENPSA2_PREFIX', '/');
        }

        $server_defaults = array
        (
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'SERVER_SOFTWARE' => __CLASS__,
            'HTTP_USER_AGENT' => $this->getName(),
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_URI' => '/',
            'REQUEST_TIME' => time(),
            'REMOTE_PORT' => '80'
        );
        $_SERVER = array_merge($server_defaults, $_SERVER);
    }

    private function _add_default_commands()
    {
        $this->_process_dir(MIDCOM_ROOT . '/midcom/exec', 'midcom');

        // we retrieve the manifests directly here, because we might get them
        // from the wrong cache (--servername does not apply here yet)
        $loader = new \midcom_helper__componentloader;
        foreach ($loader->get_manifests() as $manifest)
        {
            $exec_dir = dirname(dirname($manifest->filename)) . '/exec';
            $this->_process_dir($exec_dir, $manifest->name);
        }
        $this->add(new purgedeleted);
        $this->add(new repligard);
        $this->add(new blobdircleanup);
    }

    private function _process_dir($exec_dir, $component)
    {
        if (is_dir($exec_dir))
        {
            foreach (glob($exec_dir . '/*.php') as $file)
            {
                $command = substr(basename($file), 0, -4);
                $this->add(new exec($component . ':' . $command));
            }
        }
    }
}
