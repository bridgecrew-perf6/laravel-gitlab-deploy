<?php

declare(strict_types=1);

namespace HexideDigital\GitlabDeploy\Console;

use ErrorException;
use HexideDigital\GitlabDeploy\Classes\Replacements;
use HexideDigital\GitlabDeploy\DeployOptions\DeployParser;
use HexideDigital\GitlabDeploy\Exceptions\GitlabDeployException;
use HexideDigital\GitlabDeploy\Tasks\GitlabVariablesCreator;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

class PrepareDeployCommand extends Command
{
    // ---------------------
    // only to describe command
    // ---------------------
    protected $name = 'deploy:gitlab';
    protected $description = 'Command to prepare your deploy';


    // ---------------------
    // same static or constants, no editable
    // ---------------------
    protected static string $logTimeFormat = 'Y-m-d-H-i-s';
    // in future can be moved to config file
    protected static string $gitlabServer = 'gitlab.hexide-digital.com,188.34.141.230';
    protected static string $logFileName = 'deploy/dep-log.';
    protected static string $deployYamlFile = 'deploy/deploy-prepare.yml';


    // ---------------------
    // static patterns for replaces
    // ---------------------
    // replaces after step 2
    protected static string $deployPhpFile = '{{PROJ_DIR}}/deploy.php';
    protected static string $sshDirPath = '{{PROJ_DIR}}/.ssh_{{CI_COMMIT_REF_NAME}}';
    // replaces after step 3
    protected static string $remoteSshCredentials = '-i "{{IDENTITY_FILE}}" -p {{SSH_PORT}} "{{DEPLOY_USER}}@{{DEPLOY_SERVER}}"';


    // ---------------------
    // editable across executing
    // ---------------------
    protected int $step = 1;

    // ---------------------
    // runtime defined properties
    // ---------------------
    /** @var resource */
    protected $logFileResource;
    protected Replacements $replacements;
    protected DeployParser $accessParser;
    protected array $gitlabVars;
    protected string $deployInitialContent;


    public function handle(): int
    {
        $this->createLogFile();

        try {
            // prepare
            $this->parseAccess();

            $this->setupReplacements();
            $this->setupGitlabVariables();

            $this->deployInitialContent = $this->task_saveInitialContentOfDeployFile();

            // begin of process
            $this->task_generateSshKeysOnLocalhost();
            $this->task_copySshKeysOnRemoteHost();
            $this->task_generateSshKeysOnRemoteHost();

            $this->task_createProjectVariablesOnGitlab();
            $this->task_addGitlabToKnownHostsOnRemoteHost();

            $this->task_runDeployPrepareCommand();

            $this->task_putNewVariablesToDeployFile();
            $this->task_prepareAndCopyDotEnvFileForRemote();
            $this->task_runFirstDeployCommand();
            $this->task_rollbackDeployFileContent();

            $this->task_insertCustomAliasesOnRemoteHost();
            $this->task_ideaSetup();

        } catch (GitlabDeployException $exception) {
            $this->error($exception->getMessage());
            fclose($this->logFileResource);

            return self::FAILURE;
        }

        fclose($this->logFileResource);
        $this->newLine();

        return self::SUCCESS;
    }

    private function createLogFile()
    {
        $this->logFileResource = fopen(base_path(static::$logFileName . date(static::$logTimeFormat) . '.log'), 'w');
    }

    /** @throws GitlabDeployException */
    private function parseAccess()
    {
        $access = new DeployParser();
        $access->parseFile(base_path(static::$deployYamlFile), $this->argument('stage'));

        $this->accessParser = $access;
    }

    private function setupReplacements(): void
    {
        $accessParser = $this->accessParser;

        $server = $accessParser->getServer();
        $options = $accessParser->getOptions();

        /*-----------------------
         * step 1
         *
         * server - USER HOST SSH_PORT DEPLOY_DOMAIN DEPLOY_SERVER DEPLOY_USER DEPLOY_PASS
         */
        $this->replacements = new Replacements(
            $server->toArray()
        );

        /*-----------------------
         * step 2
         *
         * options - CI_REPOSITORY_URL DEPLOY_BASE_DIR BIN_PHP BIN_COMPOSER
         * database - DB_DATABASE DB_USERNAME DB_PASSWORD
         * mail - MAIL_HOSTNAME MAIL_USER MAIL_PASSWORD
         *
         * other - PROJ_DIR CI_COMMIT_REF_NAME
         */
        $this->replacements->mergeReplaces(array_merge(
            $options->toArray(),
            $accessParser->getDatabase()->toArray(),
            $accessParser->getMail()->toArray(),
            [
                '{{PROJ_DIR}}' => base_path(),
                '{{CI_COMMIT_REF_NAME}}' => $accessParser->stageName,

                '{{DEPLOY_BASE_DIR}}' => $this->replace($options->baseDir),
            ],
        ));

        static::$deployPhpFile = $this->replace(static::$deployPhpFile);
        static::$sshDirPath = $this->replace(static::$sshDirPath);

        /*-----------------------
         * step 3
         */
        $this->replacements->mergeReplaces([
            '{{IDENTITY_FILE}}' => static::$sshDirPath . '/id_rsa',
            '{{IDENTITY_FILE_PUB}}' => static::$sshDirPath . '/id_rsa.pub',

            '{{BASHRC_ALIASES}}' => $this->replace("_artisan()
{
local arg=\"\${COMP_LINE#php }\"

case \"\$arg\" in
artisan*)
    COMP_WORDBREAKS=\${COMP_WORDBREAKS//:}
    COMMANDS=`php74 artisan --raw --no-ansi list | sed \"s/[[:space:]].*//g\"`
            COMPREPLY=(`compgen -W \"\$COMMANDS\" -- \"\${COMP_WORDS[COMP_CWORD]}\"`)
            ;;
        *)
            COMPREPLY=( \$(compgen -o default -- \"\${COMP_WORDS[COMP_CWORD]}\") )
            ;;
        esac

    return 0
}
complete -F _artisan artisan
complete -F _artisan php74

alias artisan=\"{{BIN_PHP}} artisan\"
alias pcomposer=\"{{BIN_COMPOSER}}\" "),


            '{{DEPLOY_PHP_ENV}}' => $this->replace(<<<PHP
\$CI_REPOSITORY_URL = "{{CI_REPOSITORY_URL}}";
\$CI_COMMIT_REF_NAME = "{{CI_COMMIT_REF_NAME}}";
\$BIN_PHP = "{{BIN_PHP}}";
\$BIN_COMPOSER = "{{BIN_COMPOSER}}";
\$DEPLOY_BASE_DIR = "{{DEPLOY_BASE_DIR}}";
\$DEPLOY_SERVER = "{{DEPLOY_SERVER}}";
\$DEPLOY_USER = "{{DEPLOY_USER}}";
\$SSH_PORT = "{{SSH_PORT}}";
PHP
            )
            ,
        ]);

        static::$remoteSshCredentials = $this->replace(static::$remoteSshCredentials);
    }

    private function setupGitlabVariables(): void
    {
        $this->gitlabVars = [
            'BIN_PHP' => $this->replace('{{BIN_PHP}}'),
            'BIN_COMPOSER' => $this->replace('{{BIN_COMPOSER}}'),

            'DEPLOY_BASE_DIR' => $this->replace('{{DEPLOY_BASE_DIR}}'),
            'DEPLOY_SERVER' => $this->replace('{{DEPLOY_SERVER}}'),
            'DEPLOY_USER' => $this->replace('{{DEPLOY_USER}}'),
            'SSH_PORT' => $this->replace('{{SSH_PORT}}'),

            'SSH_PRIVATE_KEY' => '-----BEGIN OPENSSH PRIVATE ',
            'SSH_PUB_KEY' => 'rsa-ssh AAA....AAA user@host',

            'CI_ENABLED' => '0',
        ];
    }

    private function task_saveInitialContentOfDeployFile(): string
    {
        $initialContent = $this->getContent(static::$deployPhpFile);

        if (empty($initialContent)) {
            throw new GitlabDeployException('Deploy file is empty or not exists.');
        }

        return $initialContent;
    }

    private function task_generateSshKeysOnLocalhost(): void
    {
        $this->newSection('generate ssh keys - private key to gitlab (localhost)');

        $this->forceExecuteCommand('mkdir -p ' . static::$sshDirPath);

        if (!$this->isSshFilesExits() || $this->confirmAction('Should generate and override existed key?')) {
            $option = $this->isSshFilesExits() ? '-y' : '';
            $this->optionallyExecuteCommand('ssh-keygen -t rsa -f "{{IDENTITY_FILE}}" -N "" ' . $option);
        }

        $this->appendEchoLine('cat {{IDENTITY_FILE}}', 'info');
        $this->gitlabVars['SSH_PRIVATE_KEY'] = $this->getContent($this->replace('{{IDENTITY_FILE}}'));
    }

    private function isSshFilesExits(): bool
    {
        return file_exists($this->replace('{{IDENTITY_FILE}}'))
            || file_exists($this->replace('{{IDENTITY_FILE_PUB}}'));
    }

    private function task_copySshKeysOnRemoteHost(): void
    {
        $this->newSection('copy ssh to server - public key to remote host');
        $this->appendEchoLine($this->replace('can ask a password - enter <comment>{{DEPLOY_PASS}}</comment>'));

        $this->optionallyExecuteCommand('ssh-copy-id ' . static::$remoteSshCredentials);
    }

    private function task_generateSshKeysOnRemoteHost(): void
    {
        $this->newSection('Generate generate ssh-keys on remote host');

        $sshRemote = 'ssh ' . static::$remoteSshCredentials;

        if ($this->confirmAction('Generate ssh keys on remote host')) {
            $this->optionallyExecuteCommand($sshRemote . ' "ssh-keygen -t rsa -f ~/.ssh/id_rsa -N \"\""');
        }

        $this->optionallyExecuteCommand($sshRemote . ' "cat ~/.ssh/id_rsa.pub"', function ($type, $buffer) {
            $this->gitlabVars['SSH_PUB_KEY'] = $buffer;
        });

        $this->appendEchoLine('Remote pub-key: ' . $this->gitlabVars['SSH_PUB_KEY'], 'info');
    }

    /** @throws GitlabDeployException */
    private function task_createProjectVariablesOnGitlab(): void
    {
        $this->newSection('gitlab variables');

        // print to file on case if error happens
        $rows = [];
        foreach (Arr::except($this->gitlabVars, 'SSH_PRIVATE_KEY') as $key => $val) {
            $this->writeToFile($key . PHP_EOL . $val . PHP_EOL);

            $rows[] = [$key, $val];
        }

        $this->appendEchoLine('SSH_PRIVATE_KEY');
        $this->appendEchoLine(Arr::get($this->gitlabVars, 'SSH_PRIVATE_KEY', ''));

        $this->table(['key', 'value'], $rows);

        $this->appendEchoLine("tip: put SSH_PUB_KEY => Gitlab.project -> Settings -> Repository -> Deploy keys");

        if ($this->option('only-print')) {
            return;
        }

        $this->appendEchoLine('Connecting to gitlab and creating variables...');

        // if not only print, then put variables to gitlab
        $creator = new GitlabVariablesCreator(
            $this->accessParser->token,
            $this->accessParser->domain,
            $this->accessParser->projectId,
            $this->option('scope') ?: $this->accessParser->stageName
        );

        $creator->setCurrentProjectVariables($this->gitlabVars);

        list($fails, $messages) = $creator->execute();

        foreach ($messages as $message) {
            $this->comment($message);
        }

        $this->appendEchoLine('Gitlab variables created with "' . sizeof($fails) . '" fail messages');

        if (!empty($fails)) {
            foreach ($fails as $fail) {
                $this->error($fail);
            }
        }
    }

    private function task_addGitlabToKnownHostsOnRemoteHost(): void
    {
        $this->newSection('add gitlab to confirmed (known hosts) on remote host');

        if (!$this->confirmAction('Append gitlab IP to remote host known_hosts file?')) {
            return;
        }

        $knownHost = '';
        $this->optionallyExecuteCommand('ssh-keyscan -t ecdsa-sha2-nistp256 ' . static::$gitlabServer,
            function ($type, $buffer) use (&$knownHost) {
                $knownHost = trim($buffer);
            }
        );

        $sshRemote = 'ssh ' . static::$remoteSshCredentials;

        $remoteKnownHosts = '';
        $this->optionallyExecuteCommand($sshRemote . ' "cat ~/.ssh/known_hosts"', function ($type, $buffer) use (&$remoteKnownHosts) {
            $remoteKnownHosts = $buffer;
        });

        if (!Str::contains($remoteKnownHosts, $knownHost)) {
            $this->optionallyExecuteCommand($sshRemote . " 'echo \"$knownHost\" >> ~/.ssh/known_hosts'");
        } else {
            $this->appendEchoLine('Remote server already know gitlab host.');
        }
    }

    private function task_putNewVariablesToDeployFile(): void
    {
        $this->newSection('putting static env variables to deploy file');

        $this->putContentToFile(static::$deployPhpFile, [
            '/*CI_ENV*/' => $this->replace('{{DEPLOY_PHP_ENV}}'),
            '->user($DEPLOY_USER)' => $this->replace('->user($DEPLOY_USER)' . PHP_EOL . "    ->identityFile('{{IDENTITY_FILE}}')"),
        ]);
    }

    private function task_runDeployPrepareCommand(): void
    {
        $this->newSection('run deploy prepare from localhost');

        $this->optionallyExecuteCommand('php {{PROJ_DIR}}/vendor/bin/dep deploy:prepare {{CI_COMMIT_REF_NAME}} -v -o branch={{CI_COMMIT_REF_NAME}}',
            function ($type, $buffer) {
                $this->line($type . ' > ' . trim($buffer));
            }
        );
    }

    private function task_prepareAndCopyDotEnvFileForRemote(): void
    {
        $this->newSection('setup env file for remote server and move to server');

        $envHostFile = $this->replace('{{PROJ_DIR}}/.env.host');
        $this->forceExecuteCommand('cp {{PROJ_DIR}}/.env.example ' . $envHostFile);

        $mail = $this->accessParser->hasMail()
            ? []
            : [
                "MAIL_HOST=mailhog" => $this->replace("MAIL_HOST={{MAIL_HOSTNAME}}"),
                "MAIL_PORT=1025" => "MAIL_PORT=587",
                "MAIL_USERNAME=null" => $this->replace("MAIL_USERNAME={{MAIL_USER}}"),
                "MAIL_PASSWORD=null" => $this->replace("MAIL_PASSWORD={{MAIL_PASSWORD}}"),
                "MAIL_ENCRYPTION=null" => "MAIL_ENCRYPTION=tls",
                "MAIL_FROM_ADDRESS=null" => $this->replace("MAIL_FROM_ADDRESS={{MAIL_USER}}"),
            ];

        $output = new BufferedOutput();
        Artisan::call('key:generate', ['--show' => true], $output);
        $appKey = trim($output->fetch());

        $envReplaces = array_merge($mail, [
            'APP_KEY=' => 'APP_KEY=' . $appKey,
            'APP_URL=http://localhost:8000' => $this->replace('APP_URL={{DEPLOY_DOMAIN}}'),

            'DB_DATABASE=laravel_database' => $this->replace('DB_DATABASE={{DB_DATABASE}}'),
            'DB_USERNAME=laravel_database' => $this->replace('DB_USERNAME={{DB_USERNAME}}'),
            'DB_PASSWORD=laravel_password' => $this->replace('DB_PASSWORD={{DB_PASSWORD}}'),
        ]);

        $this->putContentToFile($envHostFile, $envReplaces);

        $this->optionallyExecuteCommand("scp \"$envHostFile\" " . static::$remoteSshCredentials . ":\"{{DEPLOY_BASE_DIR}}/shared/.env\"");

        $this->forceExecuteCommand('rm ' . $envHostFile);
    }

    private function task_runFirstDeployCommand(): void
    {
        $this->newSection('run deploy from local');

        $this->optionallyExecuteCommand('php {{PROJ_DIR}}/vendor/bin/dep deploy {{CI_COMMIT_REF_NAME}} -v -o branch={{CI_COMMIT_REF_NAME}}',
            function ($type, $buffer) {
                $this->line($type . ' > ' . trim($buffer));
            }
        );
    }

    private function task_rollbackDeployFileContent(): void
    {
        $this->appendEchoLine('Rollback deploy file content');

        file_put_contents(static::$deployPhpFile, $this->deployInitialContent);
    }

    private function task_insertCustomAliasesOnRemoteHost(): void
    {
        if (!$this->option('aliases')) {
            return;
        }

        $this->newSection('append custom aliases');

        $this->optionallyExecuteCommand('ssh ' . static::$remoteSshCredentials . ' \'echo "{{BASHRC_ALIASES}}" >> ~/.bashrc\'');
    }

    private function task_ideaSetup(): void
    {
        $this->newSection('IDEA - PhpStorm');

        $this->appendEchoLine($this->replace(" - change mount path
    <info>{{DEPLOY_BASE_DIR}}</info>

    - add site url
    <info>{{DEPLOY_SERVER}}</info>

    - add mapping
    <info>/current</info>

    - connect to databases (local and remote)
    port: {{SSH_PORT}}
    domain: {{DEPLOY_DOMAIN}}
    db_name: {{DB_DATABASE}}
    db_user: {{DB_USERNAME}}
    password: {{DB_PASSWORD}}"
        ));
    }


    // --------------- output --------------

    private function newSection(string $name): void
    {
        $string = strip_tags($this->step++ . '. ' . Str::ucfirst($name));

        $length = Str::length($string) + 12;

        $this->appendEchoLine('');

        $this->appendEchoLine(str_repeat('*', $length));
        $this->appendEchoLine('*     ' . $string . '     *');
        $this->appendEchoLine(str_repeat('*', $length));

        $this->appendEchoLine('');
    }

    private function appendEchoLine(?string $content, string $style = null): void
    {
        $content = $this->replace($content);

        $this->writeToFile(strip_tags($content ?: ''));
        $this->writeToConsole($content, $style);
    }

    private function writeToConsole(?string $content, string $style = null): void
    {
        $this->line($content ?: '', $style);
    }

    private function writeToFile(?string $content): void
    {
        fwrite($this->logFileResource, $content . PHP_EOL);
    }

    // --------------- content processing --------------

    private function confirmAction(string $question): bool
    {
        return $this->option('force') || $this->confirm($question, false);
    }

    private function forceExecuteCommand(string $command)
    {
        $this->runProcessCommand(true, $command);
    }

    private function optionallyExecuteCommand(string $command, callable $callable = null)
    {
        $this->runProcessCommand(false, $command, $callable);
    }

    private function runProcessCommand(bool $force, string $command, callable $callable = null): void
    {
        $command = $this->replace($command);

        $this->appendEchoLine($command, 'info');

        if (!$force && $this->option('only-print')) {
            return;
        }

        $this->line('running command...');
        $process = Process::fromShellCommandline($command);
        $process->run($callable);
    }

    private function replace(?string $subject, array $replaceMap = null): string
    {
        return $this->replacements->replace($subject ?: '', $replaceMap);
    }

    private function putContentToFile(string $file, array $replace = null): void
    {
        try {
            $content = $this->replace($this->getContent($file), $replace);

            file_put_contents($file, $content);
        } catch (ErrorException $exception) {
            $this->appendEchoLine($exception->getMessage(), 'error');
        }
    }

    private function getContent(string $filename): ?string
    {
        try {
            $content = file_get_contents($filename);
        } catch (ErrorException $exception) {
            $this->warn('Failed to open file: ' . $filename);
            $content = null;
        }

        return $content;
    }

    // --------------- command info --------------

    protected function getArguments(): array
    {
        return [
            new InputArgument('stage', InputArgument::REQUIRED, 'Deploy stage'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('force', 'f', InputOption::VALUE_NONE, 'Confirm all choices and force all commands'),
            new InputOption('aliases', null, InputOption::VALUE_NONE, 'Append custom aliases for artisan and php to ~/.bashrc'),
            new InputOption('only-print', null, InputOption::VALUE_NONE, 'Only print commands, with-out executing commands'),
            new InputOption('scope', null, InputOption::VALUE_REQUIRED, 'Set scope for gitlab variables'),
        ];
    }
}
