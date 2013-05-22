<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of ServiceController
 *
 * @author dns
 */
class ServiceController extends Controller {

    public function actionTest() {
        $email = $_GET["email"];
        $pass = $_GET["pass"];
        if (Functions::createTechEmail($email, $pass)) {
            echo "OK";
        } else {
            echo "NO";
        }
    }

    /* Уведомления о выполнении заявки */

    public function actionSendNotifyForReqs() {
        $data = ReqCnt::model()->findAllByAttributes(array("parentId" => null,
                    "send" => "0"));
        foreach ($data as $req) {
            if (($req->currentPercent["done"] == $req->currentPercent["all"]) and
                    (count($req->reqs))) {
                $this->sendNotifyForReq($req);
                $req->send = 1;
                $req->save();
            }
        }
    }

    /* Уведомления о необходимости пополнить счет */

    public function actionSendNotifyPay() {
        $sql = "SELECT activeAdverts.userId,
                       balance,
                       countActiveAdverts,
                       round(balance/7*countActiveAdverts) countDays,
                       7 * countActiveAdverts * 30 recomended
                  FROM
                       (SELECT gsUser.id userId, gsUser.email, COUNT(advBase.advertid) countActiveAdverts
                          FROM gsUser
                         INNER JOIN advBase ON advBase.userId = gsUser.id
                         WHERE advBase.active = 1
                         GROUP BY 1, 2 ) activeAdverts
                       INNER JOIN
                        (SELECT gsUser.id userId, gsUser.email, SUM(payment.summ) balance
                           FROM payment
                          INNER JOIN gsUser ON payment.userId = gsUser.id
                          WHERE payment.pay = 1
                          GROUP BY 1, 2) balance
                       ON activeAdverts.userId = balance.userId
                 WHERE round(balance/7*countActiveAdverts) <= 2 ";
        $command = Yii::app()->db->createCommand($sql);
        $res = $command->query();
        while ($row = $res->read()) {
            if (!$this->checkPayNotify($row["userId"])) {
                $user = AdUser::model()->findByPk($row["userId"]);
                $this->sendNotify("Необходимо пополнить счет",
                        "Добрый день!\r\n" .
                        ($row["balance"] < 0 ? "Деньги на Вашем счету закончились" :
                                "Ваш баланс состовляет: " . $row["balance"] . "\r\n" .
                                "Этого хватит на " . $row["countDays"] .
                                " дней обслуживания Ваших вакансий") . "\r\n" .
                        " Рекомендуем Вам пополнить счет.\r\n" .
                        " Рекомендуемая сумма пополнения: " . $row["recomended"] . " руб. \r\n" .
                        "Этой суммы хватит примерно на 30 дней обслуживания Ваших вакансий\r\n" .
                        "Напоминаем Вам что при нулевом и отрицательном балансах происходит автоматическое удаления Ваших вакансий с сайтов",
                        $user->email);
                $this->writePayNotify($row["userId"]);
            }
        }
    }

    private function checkPayNotify($userId) {
        $row = PayNotify::model()->find(
                        array("condition" => "userId = $userId AND date(`date`) = CURRENT_DATE"));
        if ($row)
            return true;
        else
            return false;
    }

    private function writePayNotify($userId) {
        $payNotify = new PayNotify();
        $payNotify->userId = $userId;
        $payNotify->save();
    }

    private function sendNotify($theme, $message, $toEmail) {
        Yii::app()->mailer->Host = '127.0.0.1';
        Yii::app()->mailer->IsSMTP();
        Yii::app()->mailer->From = 'support@economer.net';
        Yii::app()->mailer->FromName = 'Экономер';

        Yii::app()->mailer->AddReplyTo('support@economer.net');
        Yii::app()->mailer->ClearAddresses();
        Yii::app()->mailer->AddAddress($toEmail);
        //Yii::app()->mailer->AddAddress("aleksej-dyundin@yandex.ru");
        Yii::app()->mailer->Subject = $theme;
        Yii::app()->mailer->Body = $message;
        Yii::app()->mailer->Send();
    }

    public function actionSendNotifyResponse() {
        /*Посылаем уведомления о новых откликах*/
        $data = Response::model()->findAllByAttributes(array("send" => "0"));
        foreach ($data as $resp) {
            $theme = "Получен новый отклик на вакансию \"{$resp->advert->vac->jobtitle}\"";
            $message = new YiiMailMessage($theme);
            $message->view = 'notifyResponse';
            $message->setFrom(array(Yii::app()->params['adminEmail'] => 'Экономер'));
            $message->setBody(array('user'=>$user, 'message' => $message, "resp" => $resp), 'text/html');
            $message->addTo($resp->advert->user->email);
            //$message->addTo('aleksej-dyundin@yandex.ru');
            Yii::app()->mail->send($message);

            $message->setTo('aleksej-dyundin@yandex.ru');
            Yii::app()->mail->send($message);
            $resp->send = 1;
            $resp->save();

        }
        echo "Послано ".count($data)." уведомлений";
    }

    public function actionSendNotifyLeftDays() {
        /* Послать уведомления сколько дней осталось до окончания тарифа или размещения вакансии */
        $sql = "INSERT IGNORE INTO gllognotify (advertId, userTarifId, `date`, daysTo)
                SELECT advbase.advertId, NULL, CURRENT_DATE, :daysTo
                  FROM advbase
                 WHERE advbase.dateTo = DATE_ADD(CURRENT_DATE, INTERVAL :daysTo DAY)
                 UNION ALL
                SELECT null, glusertarif.Id, CURRENT_DATE, :daysTo
                  FROM glusertarif
                 WHERE glusertarif.dateTo = DATE_ADD(CURRENT_DATE, INTERVAL :daysTo DAY)";

        foreach (ConstClass::$ARR_DAYS_TO as $daysTo) {
            $command = Yii::app()->db->createCommand($sql);
            $command->bindParam(":daysTo", $daysTo, PDO::PARAM_STR);
            $command->execute();
        }
        //Выбираем все непосланные
        $notifies = LogNotify::model()->findAll("send = 0");
        foreach ($notifies as $notify) {
            if ($notify->userTarifId) {
                $email = $notify->userTarif->user->email;
                $theme = "Заканчивается срок Вашего тарифа";
                $templateName = "templateNotifyTarif";
            } elseif ($notify->advertId) {
                $email = $notify->advert->user->email;
                $theme = "Заканчивается  срок размещения вакансии";
                $templateName = "templateNotifyAdvert";
            }
            $notifyLetter = $this->renderPartial($templateName,
                            array("notify" => $notify), true);
            $this->sendNotify($theme, $notifyLetter, $email);
            $this->sendNotify($theme, $notifyLetter, 'aleksej-dyundin@yandex.ru');
            $notify->send = 1;
            $notify->save();
            echo "Посылаем  $email<br>\r\n";
        }
        echo "Послано ".count($notifies)." уведомлений";
    }

    public function actionDeleteVacs() {
        /* 1. Удаляет все вакансии которые оплачены, размещены и просрочены
          2. Удаляет все активные вакансии которые размещены по просроченным тарифам */

        //Запрос вычисляет все просроченные вакансии размещенные отдельно и вакансии размещенные по просроченным тарифам
        $sql = 'SELECT advBase.advertId
                  FROM advBase
                 INNER JOIN gllogplace ON gllogplace.advertId = advBase.advertid
                 WHERE advBase.dateTo < CURRENT_DATE
                   AND statusAdvId IN (4)
                   AND gllogplace.typeId = 2
                   AND advBase.userId NOT IN (SELECT id FROM gsuser WHERE gsuser.isTest = 1)
                 UNION ALL
                SELECT advBase.advertId
                  FROM advBase
                 INNER JOIN gllogplace ON gllogplace.advertId = advBase.advertid
                 INNER JOIN glusertarif ON glusertarif.userId = gllogplace.userId
                   WHERE advBase.statusAdvId IN (6)
                   AND advBase.userId NOT IN (SELECT id FROM gsuser WHERE gsuser.isTest = 1)
                   AND gllogplace.typeId = 4
                   AND glusertarif.dateTo < CURRENT_DATE';

        $command = Yii::app()->db->createCommand($sql);        
        $advertsToDelete = $command->queryAll();
        foreach ($advertsToDelete as $advertId) {
            $advert = AdvBase::model()->findByPk($advertId['advertId']);
            Functions::deleteAdv($advert);
        }
        $deleteVacs = count($advertsToDelete);        
        $command = Yii::app()->db->createCommand($sql)->execute();

        //И удаляем записи из таблицы glUserTarifs
        $sql = "   DELETE  FROM glUserTarif
                          WHERE glUserTarif.dateTo < CURRENT_DATE";                            
        $command = Yii::app()->db->createCommand($sql);
        $deleteTarifs = $command->execute();
        echo "Удалено тарифов: $deleteTarifs \r\n <br> Вакансий: $deleteVacs";
    }

    private function sendNotifyForReq($req) {
        $this->sendNotify("Ваша заявка выполнена",
                "Заявка: " . $req->task->name . "\r\n" .
                "Объявление: " . $req->advert->vac->jobtitle . "\r\n" .
                "Статус: выполнена\r\n\r\n" .
                "Адреса размещения Вы можете посмотреть по адресу:\r\n" .
                "http://economer.net/cabinet/urls?vac=" . $req->advert->advertId,
                $req->advert->user->email);
    }

}
?>
