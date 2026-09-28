506.ai Platform for Symfony AI
==============================

[Symfony AI](https://symfony.com/doc/current/ai/index.html) platform bridge for the
[506.ai](https://506.ai) public API ([API docs](https://developers.506.ai)).

It lets you use the LLMs, roles, assistants, files and data collections of your 506.ai
organization through Symfony AI's `PlatformInterface` — plain text, streaming and structured
output into PHP objects.

> This is a community package maintained by [CORS GmbH](https://www.cors.gmbh), it is not
> affiliated with Symfony or 506.ai.

Installation
------------

```bash
composer require cors/symfony-ai-506-platform
```

An admin creates the API key on the 506.ai platform (admin settings → API); the organization id
is shown on the same page.

Usage
-----

### Standalone

```php
use CORS\AI\Platform\Bridge\Ai506\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

$platform = Factory::createPlatform(
    host: 'acme',                 // "acme", "acme.506.ai" or "https://acme.506.ai:3003"
    organizationId: $_ENV['AI_506_ORGANIZATION_ID'],
    apiKey: $_ENV['AI_506_API_KEY'],
);

$messages = new MessageBag(
    Message::forSystem('You are a helpful baker.'),
    Message::ofUser('What is a Kornspitz?'),
);

echo $platform->invoke('gpt-4.1', $messages)->asText();
```

### Symfony

Register the bundle:

```php
// config/bundles.php
return [
    // ...
    CORS\AI\Platform\Bridge\Ai506\Bundle\CORSAi506Bundle::class => ['all' => true],
];
```

```yaml
# config/packages/cors_ai506.yaml
cors_ai506:
    host: '%env(AI_506_HOST)%'
    organization_id: '%env(AI_506_ORGANIZATION_ID)%'
    api_key: '%env(AI_506_API_KEY)%'
    # http_client: http_client     # service id, default "http_client"
    # models: ['my-custom-model']  # optional, unknown model ids are accepted anyway
```

The platform is registered as `ai.platform.ai506` and can be autowired by name:

```php
use Symfony\AI\Platform\PlatformInterface;

public function __construct(
    private readonly PlatformInterface $ai506Platform,
) {
}
```

With [`symfony/ai-bundle`](https://symfony.com/doc/current/ai/bundles/ai-bundle.html)
installed, the platform shows up in the profiler, works with
`bin/console ai:platform:invoke ai506 gpt-4.1 "Hello"` and can be used by agents:

```yaml
ai:
    agent:
        default:
            platform: 'ai.platform.ai506'
            model: 'gpt-4.1'
```

Streaming
---------

```php
$result = $platform->invoke('gpt-4.1', $messages, ['stream' => true]);

foreach ($result->asStream() as $delta) {
    echo $delta;
}
```

Structured output
-----------------

Uses 506.ai's `generateStructuredOutput` endpoint. Requires Symfony AI's structured output
`PlatformSubscriber` on the event dispatcher (registered automatically by `symfony/ai-bundle`):

```php
final class Recipe
{
    public string $title;
    public int $servings;
    /** @var list<Ingredient> */
    public array $ingredients = [];
}

$recipe = $platform->invoke('gpt-4.1', $messages, ['response_format' => Recipe::class])->asObject();
```

Options
-------

Pass options per invocation or in the model name (`gpt-4.1?mode=QA&temperature=0.5`):

| Option                   | 506.ai field                        | Description                                                       |
|--------------------------|-------------------------------------|-------------------------------------------------------------------|
| `temperature`            | `temperature`                       | 0.0–1.0, default `0.2` (mandatory for 506.ai)                     |
| `mode`                   | `selectedMode`                      | `BASIC` (default), `QA` (vector search) or `SEARCH` (web search)  |
| `role_id`                | `roleId`                            | Role shared with the API user                                     |
| `assistant_id`           | `selectedAssistantId`               | Pre-configured assistant, overrides model, role and temperature   |
| `selected_files`         | `selectedFiles`                     | `uniqueTitle`s of uploaded media                                  |
| `data_collections`       | `selectedDataCollections`           | Data collection ids (`QA` mode only)                              |
| `internal_system_prompt` | `?internalSystemPrompt`             | Default `true`                                                    |
| `citation`               | `?citation`                         | Inline citation mapping in the references (`QA` mode)             |
| `stream`                 |                                     | Stream the answer                                                 |
| `response_format`        | `jsonStructure`                     | Structured output                                                 |

In `QA` mode the answer's references are available as result metadata:

```php
$result = $platform->invoke('gpt-4.1', $messages, ['mode' => 'QA', 'data_collections' => ['…']])->getResult();
$references = $result->getMetadata()->get('references');
```

Limitations
-----------

 * **No tool calling / agent mode** — not offered by the 506.ai public API. Symfony AI agents
   work, but without tools.
 * **Text input only** — upload media through the 506.ai API and reference it via
   `selected_files`.
 * **Structured output** has no chat history: all messages are joined into the `content` field.
   The JSON schema is converted to 506.ai's format, which supports `type`, `description`,
   `properties`, `items`, `enum`, `pattern`, `minimum` and `maximum`; nullable types become
   their non-null type. No streaming.
 * **No token usage** is reported by 506.ai.
 * Media, data collection and moderation endpoints are not covered by this bridge.

License
-------

MIT, see [LICENSE.md](LICENSE.md).
