function createIdempotencyUuid(){
    if(globalThis.crypto?.randomUUID)return globalThis.crypto.randomUUID();
    if(!globalThis.crypto?.getRandomValues)throw new Error('This browser cannot create a secure order key. Update the browser or use a supported device.');
    const bytes=globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6]=(bytes[6]&0x0f)|0x40;
    bytes[8]=(bytes[8]&0x3f)|0x80;
    const hex=[...bytes].map(value=>value.toString(16).padStart(2,'0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
}

const baseNav=window.nav;
window.nav=function(){
    baseNav();
    const voidsButton=[...document.querySelectorAll('#navigation button')].find(button=>button.getAttribute('onclick')==="showPage('voids')");
    if(voidsButton)voidsButton.textContent='VOIDS / HISTORY';
    if(state.user?.role==='cashier'||state.user?.role==='superadmin'){
        const ordersButton=`<button class="${state.page==='orders'?'active':''}" onclick="showPage('orders')">ORDERS</button>`;
        const unliButton=[...document.querySelectorAll('#navigation button')].find(button=>button.getAttribute('onclick')==="showPage('unli')");
        if(unliButton)unliButton.insertAdjacentHTML('afterend',ordersButton);
        else document.getElementById('navigation').insertAdjacentHTML('beforeend',ordersButton);
    }
};

const baseShowPage=window.showPage;
window.showPage=function(page){
    if(page==='orders'){
        state.page='orders';
        nav();
        loadOrders();
        return;
    }
    baseShowPage(page);
};

const baseRenderPos=window.renderPos;
window.renderPos=function(){
    baseRenderPos();
    const screen=document.getElementById('screen');
    screen.querySelector('.category-card')?.parentElement?.classList.add('pos-category-grid');
    screen.querySelectorAll('.cart-line').forEach((row,index)=>{
        const line=state.cart[index];
        if(!line)return;
        const flavors=row.querySelector('.muted');
        if(flavors)flavors.innerHTML=line.flavors.filter(flavor=>flavor.quantity>0).map(flavor=>esc(flavor.name)).join('<br>')||'No flavor selection';
        const quantity=row.querySelector('.qty');
        if(quantity)quantity.innerHTML=`<button aria-label="Decrease quantity" onclick="adjustCart(${index},-1)">−</button><span>${line.quantity}</span><button aria-label="Increase quantity" onclick="adjustCart(${index},1)">+</button><button class="btn secondary small" aria-label="Remove item" onclick="removeCart(${index})">×</button>`;
    });
    const method=document.getElementById('payment-method');
    method?.closest('.field')?.remove();
    const amount=document.getElementById('amount-paid');
    amount?.closest('.field')?.remove();
    const checkoutButton=screen.querySelector('button[onclick*="checkout("]');
    if(checkoutButton){
        checkoutButton.textContent='Confirm order';
        if(!screen.querySelector('[data-cancel-order]'))checkoutButton.insertAdjacentHTML('afterend',' <button class="btn secondary" data-cancel-order onclick="cancelPosOrder()">Cancel</button>');
    };
}

const baseOpenTable=window.openTable;
window.openTable=function(id){
    baseOpenTable(id);
    const input=document.getElementById('paid-sets');
    if(!input)return;
    const controls=document.createElement('div');
    controls.className='paid-set-controls';
    controls.innerHTML='<button type="button" class="btn secondary" aria-label="Decrease paid sets">−</button><button type="button" class="btn secondary" aria-label="Increase paid sets">+</button>';
    const [minus,plus]=controls.querySelectorAll('button');
    const adjust=delta=>{
        input.value=String(Math.max(1,Math.min(50,(Number(input.value)||1)+delta)));
        input.dispatchEvent(new Event('input',{bubbles:true}));
    };
    minus.addEventListener('click',()=>adjust(-1));
    plus.addEventListener('click',()=>adjust(1));
    input.replaceWith(controls);
    controls.prepend(input);
};

function adjustCart(index,delta){
    const line=state.cart[index];
    if(!line)return;
    line.quantity+=delta;
    if(line.quantity<1){state.cart.splice(index,1)}else if(line.wing_count){line.flavors.forEach(flavor=>flavor.quantity=line.quantity)}
    renderPos();
}

function cancelPosOrder(){
    state.cart=[];
    state.category=null;
    sessionStorage.removeItem('checkout-key');
    renderPos();
}

async function checkout(button){
    if(!state.cart.length){notice('Add at least one item before confirming the order.',true);return}
    button.disabled=true;
    const type=document.getElementById('order-type').value;
    const feeInput=document.getElementById('delivery-fee');
    const locationInput=document.getElementById('delivery-location');
    if(type==='delivery'&&!feeInput.validity.valid){notice('Enter a valid delivery fee amount.',true);button.disabled=false;return}
    if(type==='delivery'&&!locationInput.value.trim()){notice('Enter the delivery location or address.',true);locationInput.focus();button.disabled=false;return}
    const payload={
        idempotency_key:sessionStorage.getItem('checkout-key')||createIdempotencyUuid(),
        type,
        delivery_fee_cents:type==='delivery'?cash(feeInput.value):0,
        delivery_location:type==='delivery'?locationInput.value.trim():null,
        discount_type:discountSpec('pos').type,
        discount_name:discountSpec('pos').name,
        discount_rate:discountSpec('pos').rate,
        items:state.cart.map(line=>({product_id:line.product_id,quantity:line.quantity,flavors:line.flavors.filter(flavor=>flavor.quantity>0).map(flavor=>({flavor_id:flavor.id,quantity:flavor.quantity}))}))
    };
    sessionStorage.setItem('checkout-key',payload.idempotency_key);
    try{
        const result=await post('/api/orders',payload);
        state.cart=[];
        state.category=null;
        sessionStorage.removeItem('checkout-key');
        await refreshData();
        showPage('orders');
        notice(`Order ${result.receipt.order_number} confirmed. Collect payment from the Orders module when the customer is ready.`);
    }catch(error){notice(error.message,true)}
    finally{button.disabled=false}
}

function manilaDateTime(value){
    const text=String(value||'');
    const normalized=/[zZ]|[+-]\d{2}:?\d{2}$/.test(text)?text:`${text.replace(' ','T')}+08:00`;
    return new Date(normalized).toLocaleString('en-PH',{timeZone:'Asia/Manila'});
}

async function loadOrders(){
    try{
        const data=await api('/api/orders');
        const pending=data.orders.filter(order=>order.status==='awaiting_payment');
        const completed=data.orders.filter(order=>order.status!=='awaiting_payment');
        const row=order=>`<article class="detail-line order-row"><span><strong>Order #${esc(order.customer_order_number)} <span class="muted">· Transaction #${esc(order.order_number)}</span></strong><div class="muted">Type: ${esc(order.order_type)}${order.table_label?` · ${esc(order.table_label)}`:''} · ${esc(order.cashier_name)} · ${manilaDateTime(order.created_at)}</div><div class="muted">${order.items.map(item=>`${item.quantity} × ${esc(item.product_name)}`).join(' · ')}</div></span><span class="toolbar"><strong>${money(order.total_cents)}</strong>${order.status==='awaiting_payment'?`<button class="btn" onclick="beginOrderPayment(${order.id})">Select order</button>`:`<span class="status ${order.status==='paid'?'served':''}">${esc(order.status.replaceAll('_',' '))}</span>${['paid','partially_refunded','refunded'].includes(order.status)?`<button class="btn secondary small" onclick="openReceipt(${order.id})">Receipt</button>`:''}`}</span></article>`;
        document.getElementById('screen').innerHTML=`<div class="page-head"><div><h1>Orders</h1><div class="muted">Confirmed orders from every device. Select an unpaid order when the customer is ready to pay.</div></div><button class="btn secondary" onclick="loadOrders()">Refresh</button></div><section class="panel"><h2>Awaiting payment · ${pending.length}</h2>${pending.map(row).join('')||'<div class="empty">No orders are waiting for payment.</div>'}</section><section class="panel" style="margin-top:16px"><h2>Recent orders</h2>${completed.map(row).join('')||'<div class="empty">No completed orders yet.</div>'}</section>`;
    }catch(error){notice(error.message,true)}
}

async function beginOrderPayment(id){
    try{
        const {receipt}=await api(`/api/receipts/${id}`);
        const detail=receipt.items.map(item=>`<div class="detail-line"><span>${item.quantity} × ${esc(item.product_name)}${item.flavors?.length?`<div class="muted">${item.flavors.map(flavor=>esc(flavor.flavor_name)).join('<br>')}</div>`:''}</span><strong>${money(item.line_total_cents)}</strong></div>`).join('');
        openModal(`Payment · ${receipt.order_number}`,`<p class="muted">${esc(receipt.type)} · ${esc(receipt.cashier_name)} · ${manilaDateTime(receipt.created_at)}</p>${detail}<div class="total"><span>Total due</span><span>${money(receipt.total_cents)}</span></div><div class="field"><label>Payment method</label><select id="order-payment-method" onchange="toggleOrderExactAmount(${receipt.total_cents})"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maribank">MariBank</option><option value="maya">Maya</option><option value="others">Others</option></select></div><div class="field"><label>Amount received (₱)</label><input id="order-amount-paid" type="number" min="${(receipt.total_cents/100).toFixed(2)}" step=".01" value="${(receipt.total_cents/100).toFixed(2)}"><button class="btn secondary small" id="order-exact-amount" style="margin-top:8px" onclick="setOrderExactAmount(${receipt.total_cents})">Exact amount</button></div><div class="toolbar"><button class="btn" onclick="completeOrderPayment(${receipt.id})">Confirm payment</button><button class="btn secondary" onclick="closeModal()">Cancel</button></div>`);
    }catch(error){notice(error.message,true)}
}

function toggleOrderExactAmount(total){
    const button=document.getElementById('order-exact-amount');
    if(button)button.hidden=document.getElementById('order-payment-method').value!=='cash';
    if(document.getElementById('order-payment-method').value!=='cash')setOrderExactAmount(total);
}

function setOrderExactAmount(total){document.getElementById('order-amount-paid').value=(total/100).toFixed(2)}

async function completeOrderPayment(id){
    const amount=document.getElementById('order-amount-paid');
    if(!amount.validity.valid){notice('Enter an amount at least equal to the total due.',true);return}
    try{
        const result=await post(`/api/orders/${id}/payment`,{payment_method:document.getElementById('order-payment-method').value,amount_paid_cents:cash(amount.value)});
        closeModal();
        await loadOrders();
        showReceipt(result.receipt);
    }catch(error){notice(error.message,true)}
}

window.unliRoundText=round=>`Round ${round.round_number}:\n${(round.flavors||[]).map(flavor=>`${flavor.flavor_name} × ${flavor.quantity/4} order(s) (${flavor.quantity} wings)`).concat((round.included_items||[]).map(item=>`${item} × ${round.included_quantities?.[item]??1}`)).join('\n')||'No items'}${round.total_wings?`\n${round.total_wings} wings total`:''}`;
const baseShowReceipt=window.showReceipt;
window.showReceipt=function(receipt){
    baseShowReceipt(receipt);
    if(Number(receipt.leftover_fee_cents)>0){
        state.receiptText=state.receiptText.replace(/^TOTAL:.*$/m,`Leftover fee: ${money(receipt.leftover_fee_cents)}\nTOTAL: ${money(receipt.total_cents)}`);
    }
    state.receiptText=state.receiptText.replace(`Receipt: ${receipt.order_number}`,`Order #: ${receipt.customer_order_number||`ORD-${String(receipt.id).padStart(6,'0')}`}\nTransaction #: ${receipt.order_number}`);
    for(const item of receipt.items||[]){
        const inline=item.flavors?.map(flavor=>`${flavor.flavor_name} × ${flavor.quantity} order`).join(', ');
        const vertical=item.flavors?.map(flavor=>`${flavor.flavor_name} × ${flavor.quantity} order`).join('\n   ');
        if(inline)state.receiptText=state.receiptText.replace(inline,vertical);
    }
    state.receiptText=state.receiptText.replace(/^Date: .*$/m,`Date: ${manilaDateTime(receipt.created_at)}`);
    const receiptText=document.querySelector('#receipt-paper > div');
    if(receiptText)receiptText.textContent=state.receiptText;
};

function adjustUnliFlavor(input,amount){
    input.value=String(Math.max(0,Math.min(125,(Number(input.value)||0)+amount)));
    input.dispatchEvent(new Event('input',{bubbles:true}));
}

const baseSessionPanel=window.sessionPanel;
window.sessionPanel=async function(id){
    await baseSessionPanel(id);
    for(const input of document.querySelectorAll('#round-flavors [data-round-qty]')){
        const controls=document.createElement('div');
        controls.className='round-quantity-controls';
        controls.innerHTML='<button type="button" class="btn secondary small" aria-label="Decrease flavor orders">−</button><button type="button" class="btn secondary small" aria-label="Increase flavor orders">+</button>';
        const [minus,plus]=controls.querySelectorAll('button');
        minus.addEventListener('click',()=>adjustUnliFlavor(input,-1));
        plus.addEventListener('click',()=>adjustUnliFlavor(input,1));
        input.replaceWith(controls);
        controls.prepend(input);
    }
};

const baseUpdateBilloutTotal=window.updateBilloutTotal;
window.updateBilloutTotal=function(){
    baseUpdateBilloutTotal();
    const bill=state.pendingBillout;
    if(!bill)return;
    const baseTotal=bill.total;
    bill.leftoverFeeCents=cash(document.getElementById('bill-leftover-fee')?.value||0);
    bill.total=baseTotal+bill.leftoverFeeCents;
    if(bill.leftoverFeeCents>0){
        state.receiptText=state.receiptText.replace(/^TOTAL:.*$/m,`Leftover fee: ${money(bill.leftoverFeeCents)}\nTOTAL: ${money(bill.total)}`);
    }
    const total=document.getElementById('bill-total');
    if(total)total.textContent=money(bill.total);
    const preview=document.querySelector('#bill-preview > div');
    if(preview)preview.textContent=state.receiptText;
};

const baseRenderReceiptEditor=window.renderReceiptEditor;
window.renderReceiptEditor=function(){
    baseRenderReceiptEditor();
    const screen=document.getElementById('screen');
    screen.querySelector('.panel')?.insertAdjacentHTML('beforeend','<section class="panel" style="margin-top:18px"><h2>Live preview</h2><div id="receipt-live-preview" class="receipt"></div></section>');
    document.getElementById('receipt-header')?.addEventListener('input',renderReceiptPreview);
    document.getElementById('receipt-footer')?.addEventListener('input',renderReceiptPreview);
    renderReceiptPreview();
};

function renderReceiptPreview(){
    const preview=document.getElementById('receipt-live-preview');
    if(!preview)return;
    const header=document.getElementById('receipt-header')?.value.trim();
    const footer=document.getElementById('receipt-footer')?.value.trim()||'Thank you for dining with us!';
    const content=[header,'ETIVAC WINGS POS SYSTEM','Receipt: EW-SAMPLE','Date: '+manilaDateTime(new Date().toISOString()),'Cashier: Sample cashier','Type: takeaway','------------------------------','1 × Sample platter','   Soy Garlic','   Cheese','   Chili Cheese','------------------------------','TOTAL: ₱349.00','Payment: Cash','Paid: ₱500.00','Change: ₱151.00',footer].filter(Boolean).join('\n');
    preview.innerHTML=`<img src="/images/EWLOGO.png" alt="ETIVAC WINGS logo" style="display:block;width:34mm;height:auto;max-height:18mm;object-fit:contain;margin:0 auto 2mm"><div>${esc(content)}</div>${state.receiptSettings?.qr_url?`<img class="receipt-qr" src="${esc(state.receiptSettings.qr_url)}" alt="Survey QR code">`:''}`;
}

const baseRenderCashflow=window.renderCashflow;
window.renderCashflow=function(){
    baseRenderCashflow();
    const grid=document.querySelector('#screen > section.panel .grid');
    if(grid)grid.insertAdjacentHTML('beforeend',`<article class="panel"><div class="muted">Leftover fees</div><h2>${money(state.cashflow?.leftover_fees_cents)}</h2></article>`);
    document.querySelectorAll('#screen .two-col section:last-child .detail-line').forEach((row,index)=>{
        const sale=state.cashflow?.sales?.[index];
        if(sale?.leftover_fee_cents)row.querySelector('span')?.insertAdjacentHTML('beforeend',` · Leftover fee ${money(sale.leftover_fee_cents)} · Total collected ${money(sale.collected_total_cents)}`);
    });
};

const baseLoadCashflow=window.loadCashflow;
window.loadCashflow=async function(){
    await baseLoadCashflow();
    const grid=[...document.querySelectorAll('#screen .panel')].find(panel=>panel.querySelector('h2')?.textContent.includes('Cash vs non-cash reconciliation'))?.querySelector('.grid');
    if(grid)grid.insertAdjacentHTML('beforeend',`<div><div class="muted">Cash leftover fees</div><strong>${money(state.cashflow?.cash_leftover_fees_cents)}</strong></div>`);
};

const baseAddDeliveryFeeReportUi=window.addDeliveryFeeReportUi;
window.addDeliveryFeeReportUi=function(){
    baseAddDeliveryFeeReportUi();
    const grid=document.querySelector('#screen .grid');
    if(grid)grid.insertAdjacentHTML('beforeend',`<article class="panel"><div class="muted">Leftover fees (separate)</div><h2>${money(state.report?.leftover_fees_cents)}</h2></article>`);
    document.querySelectorAll('#screen .two-col section:first-child .detail-line').forEach((row,index)=>{
        const transaction=state.report?.transactions?.[index];
        if(transaction?.leftover_fee_cents)row.querySelector('span')?.insertAdjacentHTML('beforeend',`<div class="muted">Leftover fee: ${money(transaction.leftover_fee_cents)}</div>`);
    });
};

const baseBillout=window.billout;
window.billout=function(id,total){
    if(state.pendingBillout)state.pendingBillout.leftoverFeeCents=0;
    baseBillout(id,total);
    const billTotal=document.querySelector('#modal-content .total');
    billTotal?.insertAdjacentHTML('beforebegin','<div class="field"><label for="bill-leftover-fee">Optional leftover fee (₱)</label><input id="bill-leftover-fee" type="number" min="0" max="9999999.99" step=".01" value="0" inputmode="decimal" oninput="updateBilloutTotal()"></div>');
    updateBilloutTotal();
    const button=document.querySelector('#modal-content button[onclick="createBilloutReceipt()"]');
    if(button){button.textContent='Confirm order';button.insertAdjacentHTML('afterend',' <button class="btn secondary" onclick="cancelBillout()">Cancel</button>')}
};

function cancelBillout(){state.pendingBillout=null;closeModal()}

async function createBilloutReceipt(){
    const bill=state.pendingBillout;
    if(!bill)return;
    const leftoverFee=document.getElementById('bill-leftover-fee');
    if(leftoverFee&&!leftoverFee.validity.valid){notice('Enter a valid leftover fee amount of ₱0.00 or more.',true);leftoverFee.focus();return}
    const keyName=`billout-key-${bill.id}`;
    const key=sessionStorage.getItem(keyName)||createIdempotencyUuid();
    sessionStorage.setItem(keyName,key);
    try{
        const result=await post(`/api/unli-sessions/${bill.id}/billout`,{
            idempotency_key:key,
            discount_type:bill.discount.type,
            discount_name:bill.discount.name,
            discount_rate:bill.discount.rate,
            promotion_id:bill.promotion?.id??null,
            promotion_free_customers:bill.promotion?.freeCustomers??0,
            leftover_fee_cents:bill.leftoverFeeCents||0,
            extras:bill.extras
        });
        sessionStorage.removeItem(keyName);
        state.pendingBillout=null;
        closeModal();
        await refreshData();
        showPage('orders');
        notice(`Order ${result.receipt.order_number} confirmed. Collect payment from the Orders module when the customer is ready.`);
    }catch(error){notice(error.message,true)}
}

async function confirmRound(id,button){
    const quantityFields=[...document.querySelectorAll('[data-round-qty]')];
    if(quantityFields.some(field=>!field.validity.valid)){notice('Flavor orders must be whole numbers from 0 to 125.',true);return}
    const flavors=quantityFields.map(field=>({flavor_id:Number(field.dataset.roundQty),quantity:Number(field.value)*4})).filter(flavor=>flavor.quantity>0);
    const sideFields=[...document.querySelectorAll('[data-round-included]')];
    if(sideFields.some(field=>!field.disabled&&!field.validity.valid)){notice('Included side quantities must be whole numbers from 0 to 99.',true);return}
    const requestedSides=sideFields.filter(field=>!field.disabled&&Number(field.value)>0);
    const included_items=requestedSides.map(field=>field.dataset.roundIncluded);
    const included_quantities=Object.fromEntries(requestedSides.map(field=>[field.dataset.roundIncluded,Number(field.value)]));
    if(!flavors.length&&!included_items.length){notice('Enter at least one flavor or included-side quantity.',true);return}
    const keyName=`round-key-${id}`;
    const key=sessionStorage.getItem(keyName)||createIdempotencyUuid();
    sessionStorage.setItem(keyName,key);
    button.disabled=true;
    try{
        const result=await post(`/api/unli-sessions/${id}/rounds`,{idempotency_key:key,flavors,included_items,included_quantities,instructions:document.getElementById('round-instructions').value});
        sessionStorage.removeItem(keyName);
        notice(flavors.length?`Round confirmed · ${result.round.total_wings} wings sent to kitchen.`:'Included sides request sent to kitchen.');
        await refreshData();
        sessionPanel(id);
    }catch(error){notice(error.message,true)}
    finally{button.disabled=false}
}

const touchStyles=document.createElement('style');
touchStyles.textContent='@media (hover:none){.category-card:hover{border-color:var(--line);transform:none} .btn:hover{background:var(--red)} aside button:hover{background:transparent;color:#4d535c}} .qty button,.round-quantity-controls button,.paid-set-controls button{min-width:48px;min-height:48px;touch-action:manipulation} #flavor-allocations .allocation{min-height:48px} button{touch-action:manipulation} .order-row{gap:14px} .pos-category-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;max-width:900px;margin:0 auto} .pos-category-grid .category-card{height:clamp(155px,22vw,220px);display:flex;flex-direction:column;justify-content:center;align-items:flex-start;gap:10px;padding:clamp(18px,3vw,32px);border-radius:20px;background:linear-gradient(145deg,#fff 55%,#fff1f1);box-shadow:0 10px 28px #282f3b0d} .pos-category-grid .category-card:nth-child(2){background:linear-gradient(145deg,#fff 55%,#fff7e8)} .pos-category-grid .category-card:nth-child(3){background:linear-gradient(145deg,#fff 55%,#eef6ff)} .pos-category-grid .category-card:nth-child(4){background:linear-gradient(145deg,#fff 55%,#f0f8ef)} .pos-category-grid .category-card .icon{font-size:38px;margin:0} .pos-category-grid .category-card strong{font-size:20px;letter-spacing:.04em} .pos-category-grid .category-card span{font-size:13px} #round-flavors .allocation{grid-template-columns:22px minmax(0,1fr) auto} .round-quantity-controls,.paid-set-controls{display:flex;align-items:center;gap:8px} .round-quantity-controls input[type=number],.paid-set-controls input[type=number]{width:72px;min-height:48px;text-align:center} .paid-set-controls{justify-content:center;margin-top:6px} #modal-content .detail-line>span:first-child{white-space:pre-line} @media(max-width:560px){.pos-category-grid{gap:10px}.pos-category-grid .category-card{height:150px;padding:16px;border-radius:15px}.pos-category-grid .category-card .icon{font-size:32px}.pos-category-grid .category-card strong{font-size:16px}.pos-category-grid .category-card span{font-size:11px}}';
document.head.append(touchStyles);
