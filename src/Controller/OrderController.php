<?php

namespace App\Controller;

use App\Entity\Order;
use App\Rule\DiscountCategoryBuyXGetYRule;
use App\Rule\DiscountCategoryToCheapestXPercentGteYRule;
use App\Rule\DiscountPaymentXPercentOverYRuleInterface;
use App\Rule\DiscountRuleInterface;
use App\Service\OrderService;
use App\Type\Order\IndexResponseType;
use App\Type\Order\NewRequestType;
use Doctrine\DBAL\Exception;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Annotations as OA;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * @Route("/api/orders")
 */
class OrderController extends AbstractFOSRestController
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /** Get All Orders
     * @Route("", methods={"GET"})
     * @OA\Response(
     *     response=200,
     *     description="Return Order List",
     *     @Model(type=IndexResponseType::class))
     * )
     */
    public function index() : JsonResponse
    {
        $data = [];

        $orders = $this->orderService->findAll();
        foreach ($orders as $order) {
            $orderItems = [];
            foreach($order->getItems() AS $item)
                $orderItems[] = ["productId"=>$item->getProduct()->getId(),"quantity"=>$item->getQuantity(),"unitPrice"=>$item->getUnitPrice(),"total"=>$item->getTotal()];
            $data[] = [
                'id' => $order->getId(),
                'customerId' => $order->getCustomer()->getId(),
                'items' => $orderItems,
                'total' => $order->getTotal(),
            ];
        }
        return new JsonResponse($data);
    }


    /**
     *  Add New Order
     * @Route("", methods={"POST"})
     * @OA\RequestBody(
     *     @OA\JsonContent(ref=@Model(type=NewRequestType::class))
     * )
     * @ParamConverter("newRequestType", class="App\Type\Order\NewRequestType", converter="fos_rest.request_body")
     * @OA\Response(
     *     response=200,
     *     description="Add New Order",
     * )
     * @throws \Exception
     */
    public function store(ConstraintViolationListInterface $validationErrors, NewRequestType $newRequestType) : JsonResponse|View
    {
        // validate to request
        if (\count($validationErrors) > 0) {
            return View::create($validationErrors, Response::HTTP_BAD_REQUEST);
        }
        // save order
        try {
            $orderId = $this->orderService->store($newRequestType);
        } catch (Exception $e) {
            throw new \Exception('Order not saved! Error message:'.$e->getMessage());
        }

        return new JsonResponse(['status' => true, 'message' => 'Created new order successfully with id ' . $orderId]);
    }

    /** Delete An Order
     * @Route("/orders/{order}", methods={"DELETE"})
     */
    public function delete(Order $order) : JsonResponse
    {
        $this->orderService->delete($order);

        return new JsonResponse(['status' => true, 'message' => 'Deleted order successfully with id ' . $order->getId()]);
    }

    /** Get All Orders
     * @Route("/{order}/discounts", methods={"GET"})
     * @OA\Response(
     *     response=200,
     *     description="Return Order Discounts",
     *     @Model(type="string"))
     * )
     * @Rest\View()
     */
    public function discounts(Order $order) : JsonResponse
    {

        $data = [];
        $totalDiscount = 0;

        $rules = [
            new DiscountCategoryBuyXGetYRule(2,6,1), //2 ID'li kategoriye ait bir üründen 6 adet satın alındığında, bir tanesi ücretsiz olarak verilir.
            new DiscountCategoryToCheapestXPercentGteYRule(1,2,0.2), //1 ID'li kategoriden iki veya daha fazla ürün satın alındığında, en ucuz ürüne %20 indirim yapılır.
            new DiscountPaymentXPercentOverYRuleInterface(1000,0.1), //Toplam 1000TL ve üzerinde alışveriş yapan bir müşteri, siparişin tamamından %10 indirim kazanır.
        ];

        $data['orderId'] = $order->getId();
        $subTotal = $order->getTotal();

        //apply rules
        foreach ($rules AS $rule) {
            if($rule instanceof DiscountRuleInterface) {
                //if rule is valid
                if ($rule->handle($order)) {
                    $subTotal -= $rule->getDiscountAmount();
                    $totalDiscount += $rule->getDiscountAmount();
                    $rule->setSubTotal($subTotal);
                    $data['discounts'][] = ['discountReason' => $rule->getDiscountReason(), 'discountAmount' => $rule->getDiscountAmount(), 'subTotal' => $rule->getSubTotal()];
                }
                $data['totalDiscount'] = $totalDiscount;
                $data['discountedTotal'] = $order->getTotal() - $totalDiscount;
            } // <end> if
        } // <end> foreach

        return new JsonResponse($data);

    }
}