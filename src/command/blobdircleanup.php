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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cleanup the blobdir
 * Search for corrupted (0 bytes) files
 *
 * @package midcom.console
 */
class blobdircleanup extends Command
{
    /**
     *
     * @var int
     */
    private $_file_counter = 0;

    /**
     *
     * @var string
     */
    private $_dir = "";

    protected function configure()
    {
        $this->setName('midcom:blobdircleanup')
            ->setDescription('Cleanup the blobdir')
            ->addOption('dir', null, InputOption::VALUE_OPTIONAL, 'The blobdir path', '')
            ->addOption('dry', null, InputOption::VALUE_NONE, 'If set, files and attachments will not be deleted');
    }

    public function check_dir($outerDir)
    {
        $outerDir = rtrim($outerDir, "/");
        $dirs = array_diff(scandir($outerDir), array(".", ".."));
        $files = array();
        foreach ($dirs as $d) {
            if (is_dir($outerDir."/".$d)) {
                $files = array_merge($files, $this->check_dir($outerDir."/".$d));
            } else {
                // got something
                $size = filesize($outerDir."/".$d);
                if ($size == 0) {
                    $files[] = $outerDir."/".$d;
                }
                $this->_file_counter++;
            }
        }

        return $files;
    }

    private function _determine_location($path)
    {
        return ltrim(str_replace($this->_dir, "", $path), "/");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dir = $input->getOption("dir");
        if (!is_dir($dir)) {
            $output->writeln("<comment>Unable to detect blobdir</comment>");
            return;
        }
        $this->_dir = $dir;
        $is_dry = $input->getOption("dry");
        if ($is_dry) {
            $output->writeln("<comment>Running in dry mode!</comment>");
        }

        $output->writeln("Start scanning dir: <comment>" . $dir . "</comment>");

        $files = $this->check_dir($dir);

        $output->writeln("Scanned <info>" . $this->_file_counter . "</info> files");
        $output->writeln("Found <info>" . count($files) . "</info> corrupted files:");
        if (count($files) == 0) {
            $output->writeln("<comment>Done</comment>");
            return;
        }

        $att_locations = array();
        $i = 0;
        foreach ($files as $file) {
            $i++;
            // cleanup file
            $output->writeln($i . ") " . $file);
            $info = "";
            if (!$is_dry) {
                $stat = unlink($file);
                $info = ($stat) ? "<info>Cleanup OK</info>" : "<comment>Cleanup FAILED</comment>";
            }
            if (!empty($info)) {
                $output->writeln($info);
            }
            //  determine attachment location
            $location = $this->_determine_location($file);
            $att_locations[] = $location;
        }

        // get attachments
        $qb = \midcom_db_attachment::new_query_builder();
        $qb->add_constraint("location", "IN", $att_locations);
        $attachments = $qb->execute();
        $output->writeln("Found <info>" . count($attachments) . "</info> affected attachment objects");
        if (count($attachments) == 0) {
            $output->writeln("<comment>Done</comment>");
            return;
        }

        // get parent guids
        $guids = array();
        $i = 0;
        foreach ($attachments as $att) {
            $i++;
            $output->writeln($i . ") " . $att->guid);
            $guids[] = $att->get_parent_guid_uncached();

            // cleanup attachment
            $info = "";
            if (!$is_dry) {
                $stat = $att->purge();
                $info = ($stat) ? "<info>Purge OK</info>" : "<comment>Purge FAILED, reason: " . \midcom_connection::get_error_string() . "</comment>";
            }
            if (!empty($info)) {
                $output->writeln($info);
            }
        }
        $guids = array_unique($guids);

        $output->writeln("Found <info>" . count($guids) . "</info> affected parent objects");
        if (count($guids) == 0) {
            $output->writeln("<comment>Done</comment>");
            return;
        }
        $i = 0;
        foreach ($guids as $guid) {
            $i++;
            $output->writeln($i . ") " . $guid);

            try {
                $obj = \midgard_object_class::get_object_by_guid($guid);
            } catch (midgard_error_exception $e) {
                continue;
            }

            // show info
            $ref = \midcom_helper_reflector::get($obj);
            $object_label = $ref->get_object_label($obj);
            $output->writeln("Class: " . get_class($obj));
            $output->writeln("Label: " . $object_label);
        }
        $output->writeln("<comment>Done</comment>");
    }
}
