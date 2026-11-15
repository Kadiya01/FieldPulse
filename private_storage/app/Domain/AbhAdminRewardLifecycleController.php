<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminRewardLifecycleRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** Gift catalogue, award decisions and payment/delivery event API. */
final class AbhAdminRewardLifecycleController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        $admin = $request->requireAbhAdminContext();
        $method = $request->method();
        if ($method === 'GET') {
            if (!$admin->hasPermission('rewards.manage') && !$admin->hasPermission('rewards.approve')) {
                $admin->requirePermission('rewards.manage');
            }
            $programId = Validator::intRange($request->query('program_id'), 1, 2147483647, 'program_id');
            return Response::json(['data' => AbhAdminRewardLifecycleRepository::list($programId)]);
        }
        if ($method !== 'POST') throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');

        $body = $request->json();
        $action = $body['action'] ?? null;
        if ($action === 'CREATE_GIFT') {
            $admin->requirePermission('rewards.manage');
            Validator::assertNoUnknownKeys($body, ['action','program_id','gift_code','gift_title','gift_description','available_quantity','estimated_value','currency_code'], 'gift');
            $programId = Validator::intRange($body['program_id'] ?? null, 1, 2147483647, 'program_id');
            $code = strtoupper(Validator::string($body['gift_code'] ?? null, 2, 48, 'gift_code'));
            if (preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $code) !== 1) throw ApiException::validation('Invalid gift code.', ['field'=>'gift_code']);
            $title = Validator::string($body['gift_title'] ?? null, 2, 160, 'gift_title');
            $description = isset($body['gift_description']) ? Validator::string($body['gift_description'], 1, 2000, 'gift_description') : null;
            $stock = array_key_exists('available_quantity',$body) && $body['available_quantity'] !== null
                ? Validator::intRange($body['available_quantity'], 0, 1000000000, 'available_quantity') : null;
            $value = null;
            if (isset($body['estimated_value'])) {
                if (!is_numeric($body['estimated_value']) || (float)$body['estimated_value'] < 0 || !preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', (string)$body['estimated_value'])) throw ApiException::validation('estimated_value must be a non-negative amount with up to two decimals.', ['field'=>'estimated_value']);
                $value = (string)$body['estimated_value'];
            }
            $currency = isset($body['currency_code']) ? strtoupper(Validator::string($body['currency_code'], 3, 3, 'currency_code')) : null;
            if ($currency !== null && preg_match('/^[A-Z]{3}$/', $currency) !== 1) throw ApiException::validation('currency_code must be a three-letter code.', ['field'=>'currency_code']);
            if (($value === null) !== ($currency === null)) throw ApiException::validation('Estimated value and currency must be supplied together.');
            try {
                $result = AbhAdminRewardLifecycleRepository::createGift($admin->id(),$programId,$code,$title,$description,$stock,$value,$currency);
            } catch (\PDOException $e) {
                if ((string)$e->getCode()==='23000') throw new ApiException(409,ErrorCode::STATE_CONFLICT,'Gift code already exists in this programme.');
                throw $e;
            }
            self::assertResult($result);
            return Response::json(['data'=>$result],201);
        }
        if ($action === 'UPDATE_GIFT') {
            $admin->requirePermission('rewards.manage');
            Validator::assertNoUnknownKeys($body,['action','program_id','gift_id','gift_title','gift_description','available_quantity','estimated_value','currency_code','status'],'gift update');
            $programId = Validator::intRange($body['program_id']??null,1,2147483647,'program_id');
            $giftId = Validator::intRange($body['gift_id']??null,1,2147483647,'gift_id');
            $title = Validator::string($body['gift_title']??null,2,160,'gift_title');
            $description = isset($body['gift_description']) ? Validator::string($body['gift_description'],1,2000,'gift_description') : null;
            $stock = array_key_exists('available_quantity',$body) && $body['available_quantity'] !== null
                ? Validator::intRange($body['available_quantity'],0,1000000000,'available_quantity') : null;
            $value = null;
            if (isset($body['estimated_value'])) {
                if (!is_numeric($body['estimated_value']) || (float)$body['estimated_value'] < 0 || !preg_match('/^\\d{1,13}(?:\\.\\d{1,2})?$/',(string)$body['estimated_value'])) throw ApiException::validation('Invalid estimated value.',['field'=>'estimated_value']);
                $value = (string)$body['estimated_value'];
            }
            $currency = isset($body['currency_code']) ? strtoupper(Validator::string($body['currency_code'],3,3,'currency_code')) : null;
            if (($value === null) !== ($currency === null)) throw ApiException::validation('Estimated value and currency must be supplied together.');
            if ($currency !== null && preg_match('/^[A-Z]{3}$/',$currency)!==1) throw ApiException::validation('Invalid currency code.',['field'=>'currency_code']);
            $status = $body['status'] ?? 'ACTIVE';
            if (!in_array($status,['ACTIVE','INACTIVE'],true)) throw ApiException::validation('Gift status must be ACTIVE or INACTIVE.',['field'=>'status']);
            $result = AbhAdminRewardLifecycleRepository::updateGift($admin->id(),$programId,$giftId,$title,$description,$stock,$value,$currency,$status);
            self::assertResult($result);
            return Response::json(['data'=>$result]);
        }
        if ($action === 'ASSIGN_GIFT') {
            $admin->requirePermission('rewards.manage');
            Validator::assertNoUnknownKeys($body,['action','program_id','qualification_id','gift_id','quantity'],'gift assignment');
            $result = AbhAdminRewardLifecycleRepository::assignGift(
                $admin->id(),
                Validator::intRange($body['program_id']??null,1,2147483647,'program_id'),
                Validator::intRange($body['qualification_id']??null,1,2147483647,'qualification_id'),
                Validator::intRange($body['gift_id']??null,1,2147483647,'gift_id'),
                Validator::intRange($body['quantity']??null,1,1000000,'quantity')
            );
            self::assertResult($result);
            return Response::json(['data'=>$result],201);
        }
        if ($action === 'APPROVE_AWARD') {
            $admin->requirePermission('rewards.approve');
            Validator::assertNoUnknownKeys($body,['action','award_id'],'award approval');
            $result = AbhAdminRewardLifecycleRepository::approveAward(
                $admin->id(), Validator::intRange($body['award_id']??null,1,2147483647,'award_id')
            );
            self::assertResult($result);
            return Response::json(['data'=>$result]);
        }
        if ($action === 'RECORD_PAYMENT' || $action === 'RECORD_DELIVERY') {
            $admin->requirePermission('rewards.approve');
            Validator::assertNoUnknownKeys($body,['action','award_id','quantity','amount','currency_code','reference_no','note','idempotency_key'],'reward fulfilment');
            $type = $action === 'RECORD_PAYMENT' ? 'PAYMENT' : 'DELIVERY';
            $quantity = $type === 'DELIVERY'
                ? Validator::intRange($body['quantity']??null,1,1000000,'quantity') : null;
            $amount = null; $currency = null;
            if ($type === 'PAYMENT') {
                if (!isset($body['amount']) || !is_numeric($body['amount']) || (float)$body['amount'] <= 0 || !preg_match('/^\d{1,13}(?:\.\d{1,2})?$/',(string)$body['amount'])) throw ApiException::validation('Payment amount must be positive and have up to two decimals.',['field'=>'amount']);
                $amount = (string)$body['amount'];
                $currency = strtoupper(Validator::string($body['currency_code']??null,3,3,'currency_code'));
                if (preg_match('/^[A-Z]{3}$/',$currency)!==1) throw ApiException::validation('Invalid currency code.',['field'=>'currency_code']);
            }
            $reference = isset($body['reference_no']) ? Validator::string($body['reference_no'],1,100,'reference_no') : null;
            $note = isset($body['note']) ? Validator::string($body['note'],1,2000,'note') : null;
            $key = Validator::string($body['idempotency_key']??null,36,36,'idempotency_key');
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',$key)) throw ApiException::validation('idempotency_key must be a UUID.',['field'=>'idempotency_key']);
            $result = AbhAdminRewardLifecycleRepository::recordEvent(
                $admin->id(), Validator::intRange($body['award_id']??null,1,2147483647,'award_id'),
                $type,$quantity,$amount,$currency,$reference,$note,$key
            );
            self::assertResult($result);
            return Response::json(['data'=>$result],201);
        }
        throw ApiException::validation('Unknown reward lifecycle action.',['field'=>'action']);
    }

    /** @param array<string,mixed> $result */
    private static function assertResult(array $result): void
    {
        $messages = [
            'program_not_found'=>[404,'Reward programme not found.'],
            'qualification_or_gift_not_found'=>[404,'Eligible qualification or programme gift not found.'],
            'gift_not_found'=>[404,'Programme gift not found.'],
            'award_not_found'=>[404,'Reward award not found.'],
            'program_not_active'=>[409,'Gift catalogue requires an active programme.'],
            'program_or_period_locked'=>[409,'Gift assignment requires an active programme and closed reporting period.'],
            'agent_not_eligible'=>[409,'Only evaluated eligible agents can receive a gift.'],
            'gift_not_active'=>[409,'This gift is not active.'],
            'insufficient_stock'=>[409,'Insufficient gift stock.'],
            'award_already_assigned'=>[409,'This gift is already assigned to this qualification.'],
            'separation_of_duties'=>[409,'A different administrator must approve this award.'],
            'award_not_pending'=>[409,'Only pending awards can be approved.'],
            'award_not_approved'=>[409,'Payment or delivery can be recorded only after award approval.'],
            'invalid_event'=>[422,'The fulfilment event is invalid.'],
            'delivery_exceeds_award'=>[409,'Delivery quantity exceeds the approved award quantity.'],
            'payment_exceeds_value'=>[409,'Payment total exceeds the approved estimated value.'],
            'currency_mismatch'=>[409,'Payment currency differs from the award currency.'],
            'idempotency_conflict'=>[409,'This idempotency key was already used with different event data.'],
        ];
        $error=$result['error']??null;
        if (isset($messages[$error])) {
            [$status,$message]=$messages[$error];
            throw new ApiException($status,$status===404?ErrorCode::NOT_FOUND:($status===422?ErrorCode::VALIDATION_FAILED:ErrorCode::STATE_CONFLICT),$message);
        }
    }
}
