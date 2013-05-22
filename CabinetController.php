<?php

/*
  Контроллер кабинета
 */

class CabinetController extends Controller {

    public function actionIndex() {
        $this->render("index");
    }

    public function actionDelete() {
        /* Создание заявки на удаление
         * объявления с других сайтов
         * Перед созданием заявки на удаление -
         * нужно удалить все заявки на размещение и обновление
         */
        $user = Functions::CurrentUser();
        $advert = Functions::loadAdvert();
        if ($user->isTest) {
            $this->render("error", array("message" => "В тестовом аккаунте нельзя редактировать данные"));
            Yii::app()->end();
        }
        Functions::CheckPossibilityAction(ConstClass::TYPE_TASK_DELETE);

        /*if (count($advert->placedSites) == 0) {
            $this->render("error", array("message" => "Это объявление нигде не размещено."));
            Yii::app()->end();
        }*/
        Functions::deleteAdv($advert);
        $this->render("deleteSuccess", array("advert" => $advert));
    }

    public function actionUpdate() {
        /* Алгоритм:
         * 1. Если текущая заявка - удаление - вызываем исключение
         * 2. Удаляем все заявки на редактирование (успешные, ошибочные, невыполненные)
         * 3. Там где размещена - формируем заявки на обновление
         * 4. Обновляем parentId у НЕВЫПОЛНЕННЫХ и ОШИБОЧНЫХ заявок с типом "добавление"
         */
        $advert = Functions::loadAdvert();
        Functions::checkPossibilityAction(ConstClass::TYPE_TASK_UPDATE);

        //Сохраняем currentRequestId чтобы потом удалить эту строку
        $currentRequestId = $advert->currentRequest->id;
        //Если текущий запрос - обновление - то удалем его полностью
        if ($advert->currentRequest->taskId == ConstClass::TYPE_TASK_UPDATE) {
            $advert->deleteRequests(array(ConstClass::TYPE_TASK_UPDATE));
        } else {
            //Если текущий запрос - добавление - то удаляем то что выполненно
            Yii::app()->db->createCommand(
                    " DELETE FROM reqcnt
                       WHERE advertId = {$advert->advertId}
                         AND userId = {$advert->userId}
                         AND statusForBot IN (2)
                         AND siteId > 0
                         AND taskId = " . ConstClass::TYPE_TASK_PLACE)->query();
        }
        /*По любому должна остаться родительская запись от текущего запроса
         * Меняем ей тип на обновление
         */
        if ($advert->currentRequest){
           $advert->currentRequest->taskId = ConstClass::TYPE_TASK_UPDATE;
           $advert->currentRequest->save();
        }
        foreach ($advert->placedSites as $site) {
            $reqCnt = new ReqCnt();
            $reqCnt->siteId = $site->id;
            $reqCnt->parentId = $advert->currentRequest->id;
            $reqCnt->userId = Functions::CurrentUser()->id;
            $reqCnt->taskId = ConstClass::TYPE_TASK_UPDATE;
            $reqCnt->advertId = $advert->advertId;
            $reqCnt->save();
        }        
        $this->render("updateSuccess", array("advert" => $advert));
    }

    public function actionFindCity() {
        $q = $_GET['term'];
        if (isset($q)) {
            $criteria = new CDbCriteria;
            $criteria->condition = 'name LIKE :q';
            $criteria->order = ''; // correct order-by field
            $criteria->limit = 10; // probably a good idea to limit the results
            $criteria->params = array(':q' => trim($q) . '%');
            $cities = City::model()->findAll($criteria);

            if (!empty($cities)) {
                $out = array();
                foreach ($cities as $p) {
                    $out[] = array(
                        'label' => $p->cityNameAndObl,
                        'value' => $p->cityNameAndObl,
                        'id' => $p->id,
                    );
                }
                echo CJSON::encode($out);
                Yii::app()->end();
            }
        }
    }

    public function actionProgress() {
        /* Ход выполнения заявки */
        $reqId = intval($_GET['req']);
        $req = ReqCnt::model()->findByPk($reqId);
        if ($req->user->id <> Functions::CurrentUser()->id) {
            throw new CHttpException(403, "Доступ запрещен");
        }
        $criteria = new CDbCriteria();
        $criteria->condition = 'parentId = :reqId AND siteId > 0 AND advertId  > 0';
        $criteria->params = array(':reqId' => $reqId);
        $sort = new CSort();
        $sort->sortVar = 'sort';
        $sort->defaultOrder = 'statusId DESC';
        $sort->multiSort = true;
        $sort->attributes = array(
            'site.host' => array(
                'label' => 'Сайт',
                'asc' => 'site.host ASC',
                'desc' => 'site.host DESC',
                'default' => 'asc',
            ),
            'status.name' => array(
                'label' => 'Статус',
                'asc' => 'status.id ASC',
                'desc' => 'status.id DESC',
                'default' => 'asc',
            ),
            'countSee' => array(
                'label' => 'Количество просмотров',
                'asc' => 'countSee ASC',
                'desc' => 'countSee DESC',
                'default' => 'asc',
            ),
            'status.percent' => array(
                'label' => 'Процент выполнения',
                'asc' => 'status.percent ASC',
                'desc' => 'status.percent DESC',
                'default' => 'asc',
            ),
        );
        $dataProvider = new CActiveDataProvider(ReqCnt::model()->with(array('site', 'status')),
                        array(
                            'criteria' => $criteria,
                            'sort' => $sort,
                            'pagination' => array(
                                'pageSize' => 500,
                            ),
                        )
        );
        $this->render("progress", array('dataProvider' => $dataProvider, 'req' => $req));
    }

    public function actionUrls() {
        $model = new AdvUrls("search");
        $model->advertId = intval($_GET["vac"]);
        if ($model->advert->user->id <> Functions::CurrentUser()->id) {
            throw new CHttpException(403, "Доступ запрещен");
        }
        $model->with("site");
        $this->render("urls", array('model' => $model));
    }

    public function actionProfile() {
        $user = Functions::CurrentUser();
        if ($user->profile) {
            $profile = $user->profile;
        } else {
            $profile = new AdUserProfile();
        }
        $this->performAjaxValidation(array($profile));
        if (isset($_POST['AdUserProfile'])) {
            $profile->attributes = $_POST["AdUserProfile"];
            if ($user->isTest) {
                $profile->addError( "isTest", "В тестовом акаунте нельзя редактировать данные");
            }
            if ($profile->validate(null, false)) {
                $profile->userId = $user->id;
                $profile->save();
                Functions::createReqForCreateTechEmail();
                $this->render("profileSave", array("user" => $user));
                Yii::app()->end();
            }
        }
        $this->render("profile", array("user" => $user,
            "profile" => $profile));
    }

    public function actionVac() {
        $vacId = intval($_GET["vac"]);
        $vac = AdvVac::model()->findByPk($vacId);
        if ($vac->advBase->user->id <> Functions::CurrentUser()->id) {
            throw new CHttpException(403, "Доступ запрещен");
        }
        $this->render("vac", array("vac" => $vac));
    }

    public function actionVacs() {
        $model = new AdvBase("search");
        $model->unsetAttributes();
        $model->userId = YII::app()->user->id;
        if (isset($_GET["AdvBase"])){
           $model->attributes = $_GET["AdvBase"];
        }
        $this->render("vacs", array('model' => $model));
    }

    public function actionResponse() {
        $model = new Response("search");
        
        $advert = Functions::loadAdvert();        
        if ($advert->userId !== Functions::CurrentUser()->id)
                throw new CHttpException(403, 'Доступ запрещен');
        //$model->proccess = 1;
        $model->advertId = $advert->advertId;
        $model->with("resume");
        $this->render("response", array('model' => $model));
    }

    public function actionEditVac() {
        $vacId = intval($_GET["vac"]);
        $user = Functions::CurrentUser();
        $advert = Functions::loadAdvert();

        if ($advert) {
            if ($advert->userId !== $user->id)
                throw new CHttpException(403, 'Доступ запрещен');
            if (isset($_POST['AdvBase']) || isset($_POST['AdvVac'])) {
                $advert->attributes = $_POST['AdvBase'];
                $advert->vac->attributes = $_POST['AdvVac'];
                if ($user->isTest)
                    $advert->addError("isTest", "В тестовом акаунте нельзя редактировать данные");
                $val1 = $advert->validate($advert->attributes, false);
                $val2 = $advert->vac->validate();
                if ($val1 && $val2) {
                    $advert->save();
                    $advert->vac->save();
                    if ($_POST["yt0"] == "editAndPlace") {
                        $this->redirect(Yii::app()->createUrl('/cabinet/update', array("vac" => $advert->advertId)));
                    } elseif ($_POST["yt0"] == "saveAndPlace") {
                        Functions::SendSMSNotifyToAdmin("Новая заявка на размещение: {$advert->vac->jobtitle}");
                        $this->redirect(Yii::app()->createUrl('/billing/placeVac', array("vac" => $advert->advertId)));                    
                    } else {
                        $this->render('successEdit');
                    }
                    Yii::app()->end();
                }
            }
            $this->render('newvac', array('advBase' => $advert,
                'advVac' => $advert->vac, 'user' => $user));
        }
    }

    

    public function actionAddVac() {
        $user = Functions::CurrentUser();

        $advBase = new AdvBase();
        $advVac = new AdvVac();
        $advBase->cityId = $user->profile->contCityId;
        $this->performAjaxValidation(array($advBase, $advVac));
        if (isset($_POST['AdvBase']) || isset($_POST['AdvVac'])) {
            $advBase->attributes = $_POST['AdvBase'];
            $advVac->attributes = $_POST['AdvVac'];
            if ($user->isTest)
                $advBase->addError("isTest", "В тестовом акаунте нельзя редактировать данные");
            $val1 = $advBase->validate(null, false);
            $val2 = $advVac->validate();                        
            if ($val1 && $val2) {
                $advBase->kindId = ConstClass::TYPE_SITE_JOB;
                $advBase->vac = $advVac;
                if ($advBase->save()) {
                    if ($_POST["yt0"] == "saveAndPlace") {
                        Functions::SendSMSNotifyToAdmin("Новая заявка на размещение: {$advBase->vac->jobtitle}");
                        $this->redirect(Yii::app()->createUrl('/billing/placeVac', array("vac" => $advBase->advertId)));
                    } else {
                        $this->render('successSave');
                    }
                    Yii::app()->end();
                }
            }
        }
        $this->render('newvac', array('advBase' => $advBase,
            'advVac' => $advVac, 'user' => $user));
    }

    public function actionError() {
        if ($error = Yii::app()->errorHandler->error) {
            if (Yii::app()->request->isAjaxRequest)
                echo $error['message'];
            else
                $this->render('error', $error);
        }
    }

    protected function beforeAction($action) {
        if (YII::app()->user->isGuest) {
            throw new CHttpException(403, 'Кабинет доступен лишь авторизованным пользователям');
        }
        return true;
    }

    protected function performAjaxValidation($models) {
        if (isset($_POST['ajax']) && ($_POST['ajax'] === 'advVac')
                or ($_POST['ajax'] === 'profile')) {
            echo CActiveForm::validate($models);
            Yii::app()->end();
        }
    }

    public function actionSites() {
        /* Форма сайтов с которыми пользователь работает сам */
        $currentUser = Functions::CurrentUser();
        $sites = Site::model()->findAll(
                        array('condition' => 'enable = true',
                            'order' => 'host',));
        $model = new ClosedSites;
        if (isset($_POST['ClosedSites'])) {
            $sql = "DELETE FROM glclosedsites WHERE userId = :userId";
            $command = Yii::app()->db->createCommand($sql);
            $command->bindParam(":userId", $currentUser->id);
            $command->execute();
            if (count($_POST['ClosedSites']['sites']) > 0) {
                foreach ($_POST['ClosedSites']['sites'] as $site) {
                    $sql = "INSERT IGNORE INTO glclosedsites(siteId, userId) VALUES(:siteId, :userId)";
                    $command = Yii::app()->db->createCommand($sql);
                    $command->bindParam(":siteId", $site);
                    $command->bindParam(":userId", $currentUser->id);
                    $command->execute();
                }
            }
        }
        $checkedSites = array();
        foreach ($currentUser->closedSites as $site) {
            $checkedSites[$site->id] = $site->siteId;
        }
        $this->render("sites", array("currentUser" => $currentUser,
            "siteArr" => $sites,
            "model" => $model,
            "checkedSites" => $checkedSites));
    }

    public function actionResume() {
        $model = Resume::model()->findByPk($_GET["res"]);
        $this->render("resume", array("model" => $model));
    }

    public function actionTest() {
        Yii::import('application.modules.user.UserModule');
        UserModule::sendMail("aleksej-dyundin@yandex.ru",
                        "тема", "Письмо");
    }

}
?>
