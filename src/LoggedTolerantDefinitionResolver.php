<?php
declare(strict_types = 1);

namespace LanguageServer;

class LoggedTolerantDefinitionResolver extends TolerantDefinitionResolver
{
    use LoggedDefinitionResolverTrait;
}
