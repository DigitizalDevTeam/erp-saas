<div class="row">
    <div class="col-md-5">
        <h6>{{__('Bill to')}}</h6>
        <div class="bill-to">
            @if(!empty($billing_address['name']))
            <small>
                <span>{{$billing_address['name']}}</span><br>
                <span>{{$billing_address['phone']}}</span><br>
                <span>{{$billing_address['address']}}</span><br>
                <span>{{$billing_address['city'] . ' , '.$billing_address['state'].' , '.$billing_address['country'].'.'}}</span><br>
                <span>{{$billing_address['zip']}}</span>

            </small>
            @else
                <br> -
            @endif
        </div>
    </div>
    <div class="col-md-5">
        <h6>{{__('Ship to')}}</h6>
        <div class="bill-to">
            @if(!empty($shipping_address['name']))
            <small>
                <span>{{$shipping_address['name']}}</span><br>
                <span>{{$shipping_address['phone']}}</span><br>
                <span>{{$shipping_address['address']}}</span><br>
                <span>{{$shipping_address['city'] . ' , '.$shipping_address['state'].' , '.$shipping_address['country'].'.'}}</span><br>
                <span>{{$shipping_address['zip']}}</span>

            </small>
            @else
                <br> -
            @endif
        </div>
    </div>
    <div class="col-md-2">
        <a href="#" id="remove" class="text-sm">{{__(' Remove')}}</a>
    </div>
</div>
