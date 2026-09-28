<?php

declare(strict_types=1);

/*
 * CORS GmbH
 *
 * This source file is available under the MIT license
 *
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
 * @license    https://opensource.org/license/mit MIT
 */

namespace CORS\AI\Platform\Bridge\Ai506;

use Symfony\AI\Platform\Model;

/**
 * An LLM served through the 506.ai public API. The model name is the id from GET /v1/public/models.
 *
 * Supported options (per invocation or as model name query, e.g. "gpt-4.1?mode=QA"):
 *  - temperature              float 0.0–1.0, default 0.2 (mandatory for 506)
 *  - mode                     BASIC (default) | QA | SEARCH
 *  - role_id                  id of a role shared with the API user
 *  - assistant_id             pre-configured assistant (overrides model/role/temperature on 506 side)
 *  - selected_files           list of media uniqueTitles
 *  - data_collections         list of data collection ids (QA mode only)
 *  - internal_system_prompt   bool, default true
 *  - citation                 bool, adds inline citation mapping to references (chatNoStream, QA mode)
 *  - stream                   bool
 *  - response_format          structured output (handled by Symfony AI's PlatformSubscriber)
 */
final class Ai506 extends Model
{
}
