<template>
<!-- 未使用!!!! 寫在agent index.vue -->
<div class="side-modal-wrapper">
    <div class="vertical-align">
        <div class="table-cell">
            <div class="pdd-horizon-15">
                <h4>新增網域 - 請於下方輸入完整網域內容</h4>
                <form id="data-form">
                    <div class="form-group">
                        <p class="mrg-btm-15 font-size-13">網域</p>
                        <input type="text" class="form-control" placeholder="example.invalid" name="domain">
                    </div>
                    <div class="form-group">
                        <p class="mrg-btm-15 font-size-13">啟用狀態</p>
                        <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                            <input type="checkbox" name="status" id="toggle1" checked>
                            <label for="toggle1"></label>
                        </div>
                    </div>
                    <input type="hidden" name="action" value="domain_add">
                    <input type="hidden" name="agent_id" value="<?=$_POST['id']?>">
                    <button class="btn btn-info btn-sm" id="save">確定新增</button>
                </form>
            </div>
        </div>
    </div>
</div>
</template>