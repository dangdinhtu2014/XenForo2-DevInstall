<?php

namespace XF\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use XF\Install\Data\MySql;

class DevInstall extends Command implements CustomAppCommandInterface
{
	use JobRunnerTrait;
	use Development\RequiresDevModeTrait;

	protected $cwd;

	protected $json = [];
	protected $jsonPath;

	public static function getCustomAppClass()
	{
		return 'XF\Cli\App';
	}

	protected function configure()
	{
		$this
			->setName('xf:dev-install')
			->setDescription('Installs a seeded version of XenForo intended for development')
			->addOption(
				'clear',
				null,
				InputOption::VALUE_NONE,
				'If set, existing application tables will be cleared before installing. If not set and tables are found, an error will be triggered.'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		/** @var \XF\Cli\App $app */
		$app = \XF::app();

		$data = new MySql();
		$config = \XF::config();

		$this->cwd = getcwd();
		$this->jsonPath = $this->cwd . '/dev.json';
		$this->json = json_decode(file_get_contents($this->jsonPath), true);

		$installHelper = new \XF\Install\Helper($app);

		if ($installHelper->isInstalled())
		{
			unlink($this->cwd . '/internal_data/install-lock.php');
			$output->writeln("Removing internal_data/install-lock.php... Done");
		}

		if (!$config['exists'])
		{
			// TODO: create it
			$output->writeln('<error>' . \XF::phrase('config_file_x_could_not_be_found', ['file' => 'src/config.php']) . '</error>');
			return 1;
		}

		$db = \XF::db();

		if ($installHelper->hasApplicationTables())
		{
			if ($input->getOption('clear'))
			{
				$output->write("Deleting existing tables... ");
				$installHelper->deleteApplicationTables();
				$output->writeln("Done.");
			}
			else
			{
				$output->writeln('<error>' . \XF::phrase('you_cannot_proceed_unless_tables_removed') . '</error>');
				return 1;
			}
		}

		$tables = $data->getTables($db);

		$output->writeln("Creating tables...");

		$progress = new ProgressBar($output, count($tables));
		$progress->start();

		foreach ($tables AS $query)
		{
			$db->query($query);
			$progress->advance();
		}

		$progress->finish();
		$output->writeln("");
		$output->writeln("Done.");

		$output->writeln("Creating default data...");

		$data = $data->getData();

		$progress = new ProgressBar($output, count($data));
		$progress->start();

		foreach ($data AS $dataQuery)
		{
			$db->query($dataQuery);
			$progress->advance();
		}

		$progress->finish();
		$output->writeln("");
		$output->writeln("Done. Importing data...");
		$output->writeln("");
		$devOutput = $app->developmentOutput();
		if ($devOutput->isEnabled() && $devOutput->isCoreXfDataAvailable())
		{
			$command = $this->getApplication()->find('xf-dev:import');
			$childInput = new ArrayInput([
				'command' => 'xf-dev:import',
				'--addon' => 'XF'
			]);
			$command->run($childInput, $output);
		}
		else
		{
			$command = $this->getApplication()->find('xf:rebuild-master-data');
			$childInput = new ArrayInput(['command' => 'xf:rebuild-master-data']);
			$childInput->setInteractive(false);
			$command->run($childInput, $output);
		}

		$this->runJob('xfPermissionRebuild', 'XF:PermissionRebuild');

		$output->writeln("Done. Applying installation configuration...");
		$output->writeln("");

		$installHelper->createInitialUser([
			'username' => $username = $this->json['users'][1]['username'],
			'email' => $email = $this->json['users'][1]['email']
		], $password = $this->json['dev']['password']);
		unset($this->json['users'][1]);

		$output->writeln("Seeding data... (Options)");
		/** @var \XF\Repository\Option $optionRepo */
		$optionRepo = \XF::repository('XF:Option');
		$optionRepo->updateOptions($this->json['options']);

		$output->writeln("Seeding data... (Users)");
		foreach ($this->json['users'] AS $userId => $userSeed)
		{
			$userData = $userSeed;

			/** @var \XF\Repository\User $userRepo */
			$userRepo = $app->repository('XF:User');
			$user = $userRepo->setupBaseUser();
			$user->setOption('admin_edit', true);
			$user->bulkSet($userData);
			$user->user_state = 'valid';


			/** @var \XF\Entity\UserAuth $auth */
			$auth = $user->getRelationOrDefault('Auth');
			$auth->setPassword($password);

			$user->save();
		}

		$output->writeln("Seeding data... (Threads)");
		foreach ($this->json['threads'] AS $thread)
		{
			$forum = $app->em()->find('XF:Forum', $thread['forum_id']);
			$user = $app->em()->find('XF:User', $thread['user_id']);

			\XF::setVisitor($user);

			/** @var \XF\Service\Thread\Creator $creator */
			$creator = $app->service('XF:Thread\Creator', $forum);

			$creator->setContent($thread['title'], $thread['message']);
			$creator->setUser($user);
			$creator->save();
		}

		$output->writeln("Seeding data... (Posts)");
		foreach ($this->json['posts'] AS $post)
		{
			$thread = $app->em()->find('XF:Thread', $post['thread_id']);
			$user = $app->em()->find('XF:User', $post['user_id']);

			\XF::setVisitor($user);

			/** @var \XF\Service\Thread\Replier $replier */
			$replier = $app->service('XF:Thread\Replier', $thread);

			$replier->setMessage($post['message']);
			$replier->setUser($user);
			$replier->save();
		}

		$output->writeln("Seeding data... (Profile Posts)");
		foreach ($this->json['profile_posts'] AS $profilePost)
		{
			if (!array_key_exists('username', $profilePost))
			{
				$user = $app->em()->find('XF:User', $profilePost['user_id']);
				$profilePost['username'] = $user['username'];
			}

			/** @var \XF\Entity\ProfilePost $entity */
			$entity = \XF::em()->create('XF:ProfilePost');
			$entity->bulkSet($profilePost);
			$entity->save();
		}

		$installHelper->completeInstallation();

		$output->writeln("");
		$output->writeln("All finished. Installation has been completed.");
		$output->writeln("");
		$output->writeln("Values set:");
		$output->writeln("\t* Username: $username");
		$output->writeln("\t* Email: $email");
		$output->writeln("\t* Password: " . str_repeat('*', strlen($password)) . ' (Confirmed)');
		$output->writeln("\t* Title: " . $this->json['options']['boardTitle']);
		$output->writeln("\t* URL: " . $this->json['options']['boardUrl']);

		return 0;
	}
}