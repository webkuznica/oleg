<?php

/*
 * This file is part of the MopaBootstrapBundle.
 *
 * (c) Philipp A. Mohrenweiser <phiamo@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mopa\Bundle\BootstrapBundle\Command;

use Mopa\Bridge\Composer\Adapter\ComposerAdapter;
use Mopa\Bridge\Composer\Util\ComposerPathFinder;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Command to check and create bootstrap symlink into MopaBootstrapBundle
 *
 * @author phiamo <phiamo@googlemail.com>
 */
abstract class BaseBootstrapSymlinkCommand extends ContainerAwareCommand
{
    public static $mopaBootstrapBundleName = "mopa/bootstrap-bundle";
    public static $targetSuffix = '';
    public static $pathName = 'TwitterBootstrap';
    /**
     * @var InputInterface
     */
    protected $input;
    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * Checks symlink's existence.
     *
     * @param string  $symlinkTarget The Target
     * @param string  $symlinkName   The Name
     * @param boolean $forceSymlink  Force to be a link or throw exception
     *
     * @return boolean
     *
     * @throws \Exception
     */
    public static function checkSymlink($symlinkTarget, $symlinkName, $forceSymlink = false)
    {
        if ($forceSymlink && file_exists($symlinkName) && !is_link($symlinkName)) {
            if ("link" != filetype($symlinkName)) {
                throw new \Exception($symlinkName." exists and is no link!");
            }
        } elseif (is_link($symlinkName)) {
            $linkTarget = readlink($symlinkName);
            if ($linkTarget != $symlinkTarget) {
                if (!$forceSymlink) {
                    throw new \Exception(sprintf('Symlink "%s" points to "%s" instead of "%s"', $symlinkName, $linkTarget, $symlinkTarget));
                }
                unlink($symlinkName);

                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Create the symlink.
     *
     * @param string $symlinkTarget The Target
     * @param string $symlinkName   The Name
     *
     * @throws \Exception
     */
    public static function createSymlink($symlinkTarget, $symlinkName)
    {
        if (false === @symlink($symlinkTarget, $symlinkName)) {
            throw new \Exception("An error occurred while creating symlink".$symlinkName);
        }
        if (false === $target = readlink($symlinkName)) {
            throw new \Exception("Symlink $symlinkName points to target $target");
        }
    }

    /**
     * Create the directory mirror.
     *
     * @param string $symlinkTarget The Target
     * @param string $symlinkName   The Name
     *
     * @throws \Exception
     */
    public static function createMirror($symlinkTarget, $symlinkName)
    {
        if (strlen($symlinkTarget) == 0 || empty($symlinkTarget)) {
            throw new \Exception("symlinkTarget empty!" . var_export($symlinkTarget, true));
        }
        if (strlen($symlinkName) == 0 || empty($symlinkName)) {
            throw new \Exception("symlinkName empty!" . var_export($symlinkName, true));
        }
        $filesystem = new Filesystem();
        $filesystem->mkdir($symlinkName);
        $filesystem->mirror(
            $symlinkName.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.$symlinkTarget,
            $symlinkName,
            null,
            array('copy_on_windows' => true, 'delete' => true, 'override' => true)
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription("Check and if possible install symlink to ".static::$targetSuffix)
            ->addArgument('pathTo'.static::$pathName, InputArgument::OPTIONAL, 'Where is twitters/bootstrap located?')
            ->addArgument('pathToMopaBootstrapBundle', InputArgument::OPTIONAL, 'Where is MopaBootstrapBundle located?')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force rewrite of existing symlink if possible!')
            ->addOption('manual', 'm', InputOption::VALUE_NONE, 'If set please specify pathTo'.static::$pathName.', and pathToMopaBootstrapBundle')
            ->addOption('no-symlink', null, InputOption::VALUE_NONE, 'Use hard copy/mirroring instead of symlink. This is required for Windows without administrator privileges.')
        ;
    }

    /**
     * Get Package involved
     *
     * @return string Name of twbs package
     */
    abstract protected function getTwitterBootstrapName();

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        if ($input->getOption('manual')) {
            list($symlinkTarget, $symlinkName) = $this->getBootstrapPathsFromUser();
        } elseif (false !== $composer = ComposerAdapter::getComposer($input, $output)) {
            $targetPath = $this->getContainer()->getParameter("mopa_bootstrap.bootstrap.install_path");
            $cmanager = new ComposerPathFinder($composer);
            $options = array(
                'targetSuffix' => DIRECTORY_SEPARATOR.$targetPath.static::$targetSuffix,
                'sourcePrefix' => '..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR,
            );
            list($symlinkTarget, $symlinkName) = $cmanager->getSymlinkFromComposer(
                self::$mopaBootstrapBundleName,
                $this->getTwitterBootstrapName(),
                $options
            );
        } else {
            $this->output->writeln("<error>Could not find composer and manual option not specified!</error>");

            return;
        }

        // Automatically detect if on Win XP where symlink will allways fail
        if ($input->getOption('no-symlink') || PHP_OS == "WINNT") {
            $this->output->write("Checking destination");

            if (true === self::checkSymlink($symlinkTarget, $symlinkName)) {
                $this->output->writeln(" ... <comment>symlink already exists</comment>");
            } else {
                $this->output->writeln(" ... <comment>not existing</comment>");
                $this->output->writeln("Mirroring to: ".$symlinkName);
                $this->output->write("from target: ".realpath($symlinkName.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.$symlinkTarget));
                self::createMirror($symlinkTarget, $symlinkName);
            }
        } else {
            $this->output->write("Checking symlink");
            if (false === self::checkSymlink($symlinkTarget, $symlinkName, true)) {
                $this->output->writeln(" ... <comment>not existing</comment>");
                $this->output->writeln("Creating symlink: ".$symlinkName);
                $this->output->write("for target: ".$symlinkTarget);
                self::createSymlink($symlinkTarget, $symlinkName);
            }
        }

        $this->output->writeln(" ... <info>OK</info>");
    }

    protected function getBootstrapPathsFromUser()
    {
        $symlinkTarget = $this->input->getArgument('pathTo'.static::$pathName);
        $symlinkName = $this->input->getArgument('pathToMopaBootstrapBundle');

        if (empty($symlinkName)) {
            throw new \Exception("pathToMopaBootstrapBundle not specified");
        }

        if (!is_dir(dirname($symlinkName))) {
            throw new \Exception("pathToMopaBootstrapBundle: ".dirname($symlinkName)." does not exist");
        }

        if (empty($symlinkTarget)) {
            throw new \Exception(static::$pathName." not specified");
        }

        if (substr($symlinkTarget, 0, 1) == "/") {
            $this->output->writeln("<comment>Try avoiding absolute paths, for portability!</comment>");
            if (!is_dir($symlinkTarget)) {
                throw new \Exception("Target path ".$symlinkTarget."is not a directory!");
            }
        } else {
            $symlinkTarget = $symlinkName.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR.$symlinkTarget;
        }

        if (!is_dir($symlinkTarget)) {
            throw new \Exception(static::$pathName." would resolve to: ".$symlinkTarget."\n and this is not reachable from \npathToMopaBootstrapBundle: ".dirname($symlinkName));
        }

        $dialog = $this->getHelperSet()->get('dialog');
        $text = <<<EOF
Creating the symlink: $symlinkName
Pointing to: $symlinkTarget
EOF
        ;
        $this->output->writeln(array(
            '',
            $this->getHelperSet()->get('formatter')->formatBlock($text, 'bg=blue;fg=white', true),
            '',
        ));

        if ($this->input->isInteractive() && !$dialog->askConfirmation($this->output, '<question>Should this link be created? (y/n)</question>', false)) {
            throw new \Exception("Aborting due to User not cofirming!");
        }

        return array($symlinkTarget, $symlinkName);
    }
}
