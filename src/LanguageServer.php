<?php
declare(strict_types = 1);

namespace LanguageServer;

use LanguageServer\Protocol\{
    ServerCapabilities,
    ClientCapabilities,
    TextDocumentSyncKind,
    Message,
    MessageType,
    InitializeResult,
    SymbolInformation,
    TextDocumentIdentifier,
    CompletionOptions
};
use LanguageServer\FilesFinder\{FilesFinder, ClientFilesFinder, FileSystemFilesFinder};
use LanguageServer\ContentRetriever\{ContentRetriever, ClientContentRetriever, FileSystemContentRetriever};
use LanguageServer\Index\{DependenciesIndex, GlobalIndex, Index, ProjectIndex, StubsIndex};
use AdvancedJsonRpc;
use Sabre\Event\{Loop, Promise};
use function Sabre\Event\coroutine;
use Exception;
use Throwable;
use Webmozart\PathUtil\Path;
use Webmozart\Glob\Glob;
use Sabre\Uri;

class LanguageServer extends AdvancedJsonRpc\Dispatcher
{
    /**
     * Handles textDocument/* method calls
     *
     * @var Server\TextDocument
     */
    public $textDocument;

    /**
     * Handles workspace/* method calls
     *
     * @var Server\Workspace
     */
    public $workspace;

    /**
     * @var Server\Window
     */
    public $window;

    public $telemetry;
    public $completionItem;
    public $codeLens;

    /**
     * @var ProtocolReader
     */
    protected $protocolReader;

    /**
     * @var ProtocolWriter
     */
    protected $protocolWriter;

    /**
     * @var LanguageClient
     */
    protected $client;

    /**
     * @var FilesFinder
     */
    protected $filesFinder;

    /**
     * @var ContentRetriever
     */
    protected $contentRetriever;

    /**
     * @var PhpDocumentLoader
     */
    protected $documentLoader;

    /**
     * The parsed composer.json file in the project, if any
     *
     * @var \stdClass
     */
    protected $composerJson;

    /**
     * The parsed composer.lock file in the project, if any
     *
     * @var \stdClass
     */
    protected $composerLock;

    /**
     * @var GlobalIndex
     */
    protected $globalIndex;

    /**
     * @var ProjectIndex
     */
    protected $projectIndex;

    /**
     * @var DefinitionResolver
     */
    protected $definitionResolver;

    /**
     * @param PotocolReader  $reader
     * @param ProtocolWriter $writer
     */
    public function __construct(ProtocolReader $reader, ProtocolWriter $writer)
    {
        parent::__construct($this, '/');
        $this->protocolReader = $reader;
        $this->protocolReader->on('close', function () {
            $this->shutdown();
            $this->exit();
        });
        $this->protocolReader->on('message', function (Message $msg) {
            coroutine(function () use ($msg) {
                // Ignore responses, this is the handler for requests and notifications
                if (AdvancedJsonRpc\Response::isResponse($msg->body)) {
                    return;
                }
                $result = null;
                $error = null;
                try {
                    // Invoke the method handler to get a result
                    $result = yield $this->dispatch($msg->body);
                } catch (AdvancedJsonRpc\Error $e) {
                    // If a ResponseError is thrown, send it back in the Response
                    $error = $e;
                } catch (Throwable $e) {
                    // If an unexpected error occured, send back an INTERNAL_ERROR error response
                    $error = new AdvancedJsonRpc\Error(
                        (string)$e,
                        AdvancedJsonRpc\ErrorCode::INTERNAL_ERROR,
                        null,
                        $e
                    );
                }
                // Only send a Response for a Request
                // Notifications do not send Responses
                if (AdvancedJsonRpc\Request::isRequest($msg->body)) {
                    if ($error !== null) {
                        $responseBody = new AdvancedJsonRpc\ErrorResponse($msg->body->id, $error);
                    } else {
                        $responseBody = new AdvancedJsonRpc\SuccessResponse($msg->body->id, $result);
                    }
                    $this->protocolWriter->write(new Message($responseBody));
                }
            })->otherwise('\\LanguageServer\\crash');
        });
        $this->protocolWriter = $writer;
        $this->client = new LanguageClient($reader, $writer);
    }

    /**
     * The initialize request is sent as the first request from the client to the server.
     *
     * @param ClientCapabilities $capabilities The capabilities provided by the client (editor)
     * @param string|null $rootPath The rootPath of the workspace. Is null if no folder is open.
     * @param int|null $processId The process Id of the parent process that started the server. Is null if the process has not been started by another process. If the parent process is not alive then the server should exit (see exit notification) its process.
     * @return Promise <InitializeResult>
     */
    public function initialize(ClientCapabilities $capabilities, string $rootPath = null, int $processId = null): Promise
    {
        return coroutine(function () use ($capabilities, $rootPath, $processId) {

            if ($capabilities->xfilesProvider) {
                $this->filesFinder = new ClientFilesFinder($this->client);
            } else {
                $this->filesFinder = new FileSystemFilesFinder;
            }

            if ($capabilities->xcontentProvider) {
                $this->contentRetriever = new ClientContentRetriever($this->client);
            } else {
                $this->contentRetriever = new FileSystemContentRetriever;
            }

            $dependenciesIndex = new DependenciesIndex;
            $sourceIndex = new Index;
            $this->projectIndex = new ProjectIndex($sourceIndex, $dependenciesIndex);
            $stubsIndex = StubsIndex::read();
            $this->globalIndex = new GlobalIndex($stubsIndex, $this->projectIndex);

            // The DefinitionResolver should look in stubs, the project source and dependencies
            $this->definitionResolver = new DefinitionResolver($this->globalIndex);

            $this->documentLoader = new PhpDocumentLoader(
                $this->contentRetriever,
                $this->projectIndex,
                $this->definitionResolver
            );

            if ($rootPath !== null) {
                yield $this->beforeIndex($rootPath);
                $this->index($rootPath)->otherwise('\\LanguageServer\\crash');
            }

            // Find composer.json
            if ($this->composerJson === null) {
                $composerJsonFiles = yield $this->filesFinder->find(Path::makeAbsolute('**/composer.json', $rootPath));
                if (!empty($composerJsonFiles)) {
                    $this->composerJson = json_decode(yield $this->contentRetriever->retrieve($composerJsonFiles[0]));
                }
            }
            // Find composer.lock
            if ($this->composerLock === null) {
                $composerLockFiles = yield $this->filesFinder->find(Path::makeAbsolute('**/composer.lock', $rootPath));
                if (!empty($composerLockFiles)) {
                    $this->composerLock = json_decode(yield $this->contentRetriever->retrieve($composerLockFiles[0]));
                }
            }

            if ($this->textDocument === null) {
                $this->textDocument = new Server\TextDocument(
                    $this->documentLoader,
                    $this->definitionResolver,
                    $this->client,
                    $this->globalIndex,
                    $this->composerJson,
                    $this->composerLock
                );
            }
            if ($this->workspace === null) {
                $this->workspace = new Server\Workspace(
                    $this->projectIndex,
                    $dependenciesIndex,
                    $sourceIndex,
                    $this->composerLock,
                    $this->documentLoader
                );
            }

            $serverCapabilities = new ServerCapabilities();
            // Ask the client to return always full documents (because we need to rebuild the AST from scratch)
            $serverCapabilities->textDocumentSync = TextDocumentSyncKind::FULL;
            // Support "Find all symbols"
            $serverCapabilities->documentSymbolProvider = true;
            // Support "Find all symbols in workspace"
            $serverCapabilities->workspaceSymbolProvider = true;
            // Support "Format Code"
            $serverCapabilities->documentFormattingProvider = false;
            // Support "Go to definition"
            $serverCapabilities->definitionProvider = true;
            // Support "Find all references"
            $serverCapabilities->referencesProvider = false;
            // Support "Hover"
            $serverCapabilities->hoverProvider = true;
            // Support "Completion"
            $serverCapabilities->completionProvider = null;
//            $serverCapabilities->completionProvider = new CompletionOptions;
//            $serverCapabilities->completionProvider->resolveProvider = false;
//            $serverCapabilities->completionProvider->triggerCharacters = ['$', '>'];
            // Support global references
            $serverCapabilities->xworkspaceReferencesProvider = false;
            $serverCapabilities->xdefinitionProvider = false;
            $serverCapabilities->xdependenciesProvider = false;

            return new InitializeResult($serverCapabilities);
        });
    }

    /**
     * The shutdown request is sent from the client to the server. It asks the server to shut down, but to not exit
     * (otherwise the response might not be delivered correctly to the client). There is a separate exit notification that
     * asks the server to exit.
     *
     * @return void
     */
    public function shutdown()
    {
        unset($this->project);
    }

    /**
     * A notification to ask the server to exit its process.
     *
     * @return void
     */
    public function exit()
    {
        exit(0);
    }

    /**
     * Called before indexing, can return a Promise
     *
     * @param string $rootPath
     */
    protected function beforeIndex(string $rootPath)
    {
    }

    /**
     * Will read and parse the passed source files in the project and add them to the appropiate indexes
     *
     * @param string $rootPath
     * @return Promise <void>
     */
    protected function index(string $rootPath): Promise
    {
        return coroutine(function () use ($rootPath) {

            $pattern = Path::makeAbsolute('**/*.php', $rootPath);
            $uris = yield $this->filesFinder->find($pattern);

            $count = count($uris);

            $startTime = microtime(true);

            foreach (['Collecting definitions and static references', 'Collecting dynamic references'] as $run => $text) {
                $this->client->window->logMessage(MessageType::INFO, $text);
                foreach ($uris as $i => $uri) {
                    if ($this->documentLoader->isOpen($uri)) {
                        continue;
                    }

                    // Give LS to the chance to handle requests while indexing
                    yield timeout();
                    $this->client->window->logMessage(
                        MessageType::LOG,
                        "Parsing file $i/$count: {$uri}"
                    );
                    try {
                        $document = yield $this->documentLoader->load($uri);
                        if (!$document->isVendored()) {
                            $this->client->textDocument->publishDiagnostics($uri, $document->getDiagnostics());
                        }
                    } catch (ContentTooLargeException $e) {
                        $this->client->window->logMessage(
                            MessageType::INFO,
                            "Ignoring file {$uri} because it exceeds size limit of {$e->limit} bytes ({$e->size})"
                        );
                    } catch (Exception $e) {
                        $this->client->window->logMessage(
                            MessageType::ERROR,
                            "Error parsing file {$uri}: " . (string)$e
                        );
                    }
                }
                if ($run === 0) {
                    $this->projectIndex->setStaticComplete();
                } else {
                    $this->projectIndex->setComplete();
                }
                $duration = (int)(microtime(true) - $startTime);
                $mem = (int)(memory_get_usage(true) / (1024 * 1024));
                $this->client->window->logMessage(
                    MessageType::INFO,
                    "All $count PHP files parsed in $duration seconds. $mem MiB allocated!!!"
                );
            }
        });
    }
}
