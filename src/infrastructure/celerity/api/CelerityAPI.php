<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

final class CelerityAPI {

  private static $response;

  public static function getStaticResourceResponse() {
    if (empty(self::$response)) {
      self::$response = new CelerityStaticResourceResponse();
    }
    return self::$response;
  }

}

function require_celerity_resource($symbol) {
  $response = CelerityAPI::getStaticResourceResponse();
  $response->requireResource($symbol);
}

function celerity_generate_unique_node_id() {
  static $uniq = 0;
  $response = CelerityAPI::getStaticResourceResponse();
  $block = $response->getMetadataBlock();

  return 'UQ'.$block.'_'.($uniq++);
}

