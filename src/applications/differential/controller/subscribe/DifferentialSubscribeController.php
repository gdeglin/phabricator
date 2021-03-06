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

class DifferentialSubscribeController extends DifferentialController {

  private $id;
  private $action;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
    $this->action = $data['action'];
  }

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();

    $revision = id(new DifferentialRevision())->load($this->id);
    if (!$revision) {
      return new Aphront404Response();
    }

    if (!$request->isFormPost()) {
      // TODO: This dialog is silly but we're CSRF-able otherwise.

      $dialog = new AphrontDialogView();

      switch ($this->action) {
        case 'add':
          $button = 'Subscribe';
          $title = 'Subscribe to Revision';
          $prompt = 'Really subscribe to this revision?';
          break;
        case 'rem':
          $button = 'Unsubscribe';
          $title = 'Unsubscribe from Revision';
          // TODO: Once herald is in, add a notice about not getting any more
          // herald notifications.
          $prompt = 'Really unsubscribe from this revision?';
          break;
        default:
          return new Aphront400Response();
      }

      $dialog
        ->setUser($user)
        ->setTitle($title)
        ->appendChild('<p>'.$prompt.'</p>')
        ->setSubmitURI($request->getRequestURI())
        ->addSubmitButton($button)
        ->addCancelButton('/D'.$revision->getID());

      return id(new AphrontDialogResponse())->setDialog($dialog);
    }

    $revision->loadRelationships();
    $phid = $user->getPHID();

    switch ($this->action) {
      case 'add':
        DifferentialRevisionEditor::addCC(
          $revision,
          $phid,
          $phid);
        $unsubscribed = $revision->getUnsubscribed();
        if (isset($unsubscribed[$phid])) {
          unset($unsubscribed[$phid]);
          $revision->setUnsubscribed($unsubscribed);
          $revision->save();
        }
        break;
      case 'rem':
        DifferentialRevisionEditor::removeCC(
          $revision,
          $user->getPHID(),
          $user->getPHID());
        $unsubscribed = $revision->getUnsubscribed();
        if (empty($unsubscribed[$phid])) {
          $unsubscribed[$phid] = true;
          $revision->setUnsubscribed($unsubscribed);
          $revision->save();
        }
        break;
      default:
        return new Aphront400Response();
    }

    return id(new AphrontRedirectResponse())->setURI('/D'.$revision->getID());
  }
}
