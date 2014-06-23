<?php
/**
 * @package midcom.console
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace midcom\console\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PDO;

/**
 * Clean up repligard table
 *
 * @package midcom.console
 */
class repligard extends Command
{
    private $_db;

    protected function configure()
    {
        $this->setName('midcom:repligard')
            ->setDescription('Remove purged objects from repligard table');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getHelperSet()->get('dialog');
        $defaults = array
        (
            'username' => null,
            'password' => null,
            'host' => 'localhost',
            'dbtype' => 'MySQL',
            'dbname' => 'midgard'
        );

        if (!extension_loaded('midgard'))
        {
            $config = \midgard_connection::get_instance()->config;
            $defaults['username'] = $config->dbuser;
            $defaults['password'] = $config->dbpass;
            $defaults['host'] = $config->host;
            $defaults['dbtype'] = $config->dbtype;
            $defaults['dbname'] = $config->database;
        }

        $host = $dialog->ask($output, '<question>DB host:</question> [' . $defaults['host'] . ']', $defaults['host']);
        $dbtype = $dialog->ask($output, '<question>DB type:</question> [' . $defaults['dbtype'] . ']', $defaults['dbtype']);
        $dbname = $dialog->ask($output, '<question>DB name:</question> [' . $defaults['dbname'] . ']', $defaults['dbname']);

        if (empty($defaults['username']))
        {
            $username = $dialog->ask($output, '<question>DB Username:</question> ');
            $password = $dialog->askHiddenResponse($output, '<question>DB Password:</question> ', false);
        }

        $dsn = strtolower($dbtype) . ':host=' . $host . ';dbname=' . $dbname;
        $this->_db = new PDO($dsn, $username, $password);
        $result = $this->_run('SELECT COUNT(guid) FROM repligard WHERE object_action=2');
        $output->writeln('Found <info>' . $result->fetchColumn() . '</info> entries for purged objects');
        if ($this->_confirm($output, 'Delete all rows?'))
        {
            $result = $this->_run('DELETE FROM repligard WHERE object_action=2', 'exec');
            $output->writeln('Deleted <comment>' . $result . '</comment> rows');
        }
    }

    private function _confirm(OutputInterface $output, $question, $default = 'n')
    {
        $question = '<question>' . $question;
        $options = array('y', 'n');
        $default = strtolower($default);
        foreach ($options as &$option)
        {
            if ($option === $default)
            {
                $option = strtoupper($option);
            }
        }
        $question .= ' [' . implode('|', $options). ']</question> ';
        $dialog = $this->getHelperSet()->get('dialog');
        return ($dialog->ask($output, $question, $default, $options) === 'y');
    }

    private function _run($stmt, $command = 'query')
    {
        $result = $this->_db->$command($stmt);
        if ($result === false)
        {
            throw new \RuntimeException(implode("\n", $this->_db->errorInfo()));
        }
        return $result;
    }
}
