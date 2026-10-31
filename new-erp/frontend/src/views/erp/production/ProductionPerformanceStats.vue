<template>
  <section class="production-page perf-stats-page">
    <!-- 统一主数据中心风格页头 -->
    <header class="page-head">
      <div class="head-left">
        <div class="head-icon"><i class="el-icon-s-data" /></div>
        <div class="head-text">
          <div class="title-row">
            <h1 class="page-title">提成与工时统计</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">{{ activeTabLabel }}</el-tag>
            <el-tag v-if="tab === 'month' || tab === 'production'" size="small" type="info" effect="plain" class="head-tag">
              {{ month }} 月份
            </el-tag>
            <el-tag v-if="tab === 'month' && monthData" size="small" type="info" effect="plain" class="head-tag">
              结算人员 {{ filteredMonthEmployees.length }} 人
            </el-tag>
            <el-tag v-if="tab === 'production' && prodData" size="small" type="info" effect="plain" class="head-tag">
              在岗人员 {{ prodPeople.length }} 人
            </el-tag>
            <el-tag v-if="tab === 'wos'" size="small" type="info" effect="plain" class="head-tag">
              共 {{ woTotal }} 批工单/开料
            </el-tag>
            <el-tag v-if="tab === 'orders'" size="small" type="info" effect="plain" class="head-tag">
              共 {{ total }} 张订单
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" plain icon="el-icon-refresh" class="btn-Head-refresh" :loading="loading || monthLoading || prodLoading || woLoading || batchLoading" @click="reload">刷新数据</el-button>
        <el-button
          size="small"
          type="success"
          icon="el-icon-s-operation"
          class="btn-theme-create"
          @click="$router.push('/production/performance/templates')"
        >提成比例模板</el-button>
      </div>
    </header>

    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>按订单与出库实绩统计工序提成和工时：工时挂在生产出的产品上，随产品出库（或装进整机出库）按比例结算，未出库部分自动结转下月；领用备货半成品或集中开料按实际领用占比核算。</span>
    </div>

    <div class="perf-main-card">
      <el-tabs v-model="tab" class="archive-tabs perf-tabs">
        <!-- ==================== Tab 1: 月度结算 ==================== -->
        <el-tab-pane name="month">
          <span slot="label" class="tab-label-wrap">
            <i class="el-icon-date" />
            <span>月度结算</span>
            <span v-if="monthData && monthData.employees" class="tab-count-pill">{{ monthData.employees.length }}</span>
          </span>

          <div class="perf-toolbar">
            <div class="toolbar-left">
              <span class="filter-label">核算月份</span>
              <el-date-picker
                v-model="month"
                type="month"
                value-format="yyyy-MM"
                size="small"
                :clearable="false"
                placeholder="选择月份"
                style="width: 145px"
                @change="loadMonth"
              />
              <el-input
                v-model="monthKeyword"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                placeholder="筛选人员姓名 / 工单号 / 产品名称"
                style="width: 245px"
              />
              <el-button size="small" type="success" class="btn-theme-create" icon="el-icon-search" :loading="monthLoading" @click="loadMonth">查询</el-button>
              <el-button size="small" icon="el-icon-refresh-left" @click="resetMonthFilter">重置</el-button>
            </div>
            <div class="toolbar-right">
              <el-button
                v-if="filteredMonthEmployees.length"
                size="small"
                plain
                :icon="monthAllExpanded ? 'el-icon-arrow-up' : 'el-icon-arrow-down'"
                @click="toggleExpandAllMonth"
              >{{ monthAllExpanded ? '收起全部工单明细' : '展开全部工单明细' }}</el-button>
            </div>
          </div>

          <template v-if="monthData">
            <!-- 月度结转公式总览卡 -->
            <div class="formula-overview-grid">
              <div class="formula-card">
                <div class="formula-label">上月结转（月初未结）</div>
                <div class="formula-val">{{ hours(monthData.totals.opening) }} <small>小时</small></div>
                <div class="formula-sub">往月已完工尚未出库</div>
              </div>
              <div class="formula-op">+</div>
              <div class="formula-card">
                <div class="formula-label">本月新做工时</div>
                <div class="formula-val">{{ hours(monthData.totals.new) }} <small>小时</small></div>
                <div class="formula-sub">本月车间登记报工</div>
              </div>
              <div class="formula-op">−</div>
              <div class="formula-card is-ok">
                <div class="formula-label">本月出库结算</div>
                <div class="formula-val">{{ hours(monthData.totals.settled) }} <small>小时</small></div>
                <div class="formula-sub">随产品发货正式结算</div>
              </div>
              <div class="formula-op">−</div>
              <div class="formula-card is-danger">
                <div class="formula-label">本月报废（单独列）</div>
                <div class="formula-val">{{ hours(monthData.totals.scrap) }} <small>小时</small></div>
                <div class="formula-sub">报废审批不摊给合格品</div>
              </div>
              <div class="formula-op">=</div>
              <div class="formula-card is-warn">
                <div class="formula-label">结转下月（未出库）</div>
                <div class="formula-val">{{ hours(monthData.totals.closing) }} <small>小时</small></div>
                <div class="formula-sub">留待后续出库月份结算</div>
              </div>
              <div class="formula-card is-money">
                <div class="formula-label">本月结算提成合计</div>
                <div class="formula-val">{{ moneySymbol(monthData.totals.commission) }}</div>
                <div class="formula-sub">按出库订单折后金额×比例核算</div>
              </div>
            </div>

            <div v-if="monthData.returns && monthData.returns.length" class="return-box">
              <div class="sec-title">
                <i class="el-icon-warning-outline" />
                本月客户退货记录（仅记录备查，不自动倒扣工时和提成；售后按责任人手动处理）
              </div>
              <el-table :data="monthData.returns" size="mini" border>
                <el-table-column prop="date" label="到货日期" width="115" />
                <el-table-column prop="no" label="退货单号" min-width="150" />
                <el-table-column prop="order_no" label="关联订单" min-width="145" />
                <el-table-column prop="item_name" label="退货商品" min-width="160" show-overflow-tooltip />
                <el-table-column prop="qty" label="数量" width="85" align="right" />
                <el-table-column label="发货时对应工单" min-width="175">
                  <template slot-scope="{ row }">
                    <span v-for="w in row.work_orders" :key="w.work_order_id" class="emp">
                      <router-link :to="`/production/work-orders/${w.work_order_id}`" class="wo-link">{{ w.work_order_no }}</router-link>
                    </span>
                  </template>
                </el-table-column>
                <el-table-column label="工时 / 提成影响" min-width="175">
                  <template slot-scope="{ row }"><span class="muted">{{ row.adjustment_status }}</span></template>
                </el-table-column>
              </el-table>
            </div>

            <el-table
              v-loading="monthLoading"
              :data="filteredMonthEmployees"
              size="small"
              border
              row-key="employee_legacy_id"
              :expand-row-keys="monthExpandedKeys"
              empty-text="当前月份暂无工时与提成结算数据"
              class="perf-table clickable"
              @row-click="toggleMonthRow"
            >
              <el-table-column type="expand" width="44">
                <template slot-scope="{ row }">
                  <div class="sub-detail-panel">
                    <div class="sub-detail-head">
                      <span class="sub-detail-title">
                        <i class="el-icon-s-order" />
                        {{ row.employee_name }} · {{ month }} 工单与开料月度结算明细
                      </span>
                      <span class="sub-detail-meta">共 {{ (row.rows || []).length }} 项关联记录</span>
                    </div>
                    <el-table :data="row.rows" size="mini" border class="inner-sub-table">
                      <el-table-column label="工单 / 批次（工时挂在产出产品上）" min-width="225">
                        <template slot-scope="{ row: r }">
                          <div class="wo-cell-main">
                            <router-link v-if="r.work_order_id" :to="`/production/work-orders/${r.work_order_id}`" class="wo-link" @click.native.stop>{{ r.work_order_no }}</router-link>
                            <span v-else class="mono-bold">{{ r.work_order_no }}</span>
                            <el-tag size="mini" :type="sourceTagType(r.source_label)" effect="plain">{{ r.source_label }}</el-tag>
                          </div>
                          <div class="muted" style="margin-top: 2px">{{ r.item_name }} · {{ (r.operations || []).join('、') }}</div>
                        </template>
                      </el-table-column>
                      <el-table-column label="产品出库与在库情况" min-width="235">
                        <template slot-scope="{ row: r }">
                          <div>
                            <el-tag size="mini" :type="statusType(r.status_label)" effect="plain">{{ r.status_label }}</el-tag>
                            <span class="ok-text" style="margin-left: 6px">已出库 <b>{{ r.shipped_qty }}</b></span>
                            <span class="muted"> / 产出 {{ r.output_qty }}</span>
                          </div>
                          <div v-if="r.pending_qty > 0" class="warn-text" style="font-size: 12px; margin-top: 2px">
                            未出库 {{ r.pending_qty }}
                            <template v-if="r.consumed_pending_qty">（{{ r.kind === 'cut' ? '已被工单领用待出库' : '已装进整机待出库' }} {{ r.consumed_pending_qty }}）</template>
                            <template v-if="r.in_stock_qty">（在库 {{ r.in_stock_qty }}）</template>
                            <template v-if="r.other_qty">（{{ r.kind === 'cut' ? '开了还没领' : '未完工/其他' }} {{ r.other_qty }}）</template>
                            <template v-if="r.scrapped_qty">（报废 {{ r.scrapped_qty }}）</template>
                          </div>
                        </template>
                      </el-table-column>
                      <el-table-column label="本月出库去向" min-width="165">
                        <template slot-scope="{ row: r }">
                          <div v-for="(x, i) in (r.shipped_in_month || [])" :key="i" :class="{ 'warn-text': x.type === 'return' }">
                            {{ x.date }} {{ x.type_label }} {{ x.sales_order_no }} × {{ x.qty }}
                          </div>
                          <span v-if="!(r.shipped_in_month || []).length" class="muted">本月没有出库</span>
                        </template>
                      </el-table-column>
                      <el-table-column label="月初未结(h)" width="96" align="right">
                        <template slot-scope="{ row: r }">{{ hours(r.opening) }}</template>
                      </el-table-column>
                      <el-table-column label="本月新做(h)" width="96" align="right">
                        <template slot-scope="{ row: r }">{{ hours(r.new) }}</template>
                      </el-table-column>
                      <el-table-column label="本月结算(h)" width="96" align="right">
                        <template slot-scope="{ row: r }">
                          <b :class="r.settled < 0 ? 'bad-text' : 'ok-text'">{{ hours(r.settled) }}</b>
                        </template>
                      </el-table-column>
                      <el-table-column label="本月报废(h)" width="96" align="right">
                        <template slot-scope="{ row: r }">
                          <span :class="r.scrap > 0 ? 'bad-text' : 'muted'">{{ hours(r.scrap) }}</span>
                        </template>
                      </el-table-column>
                      <el-table-column label="结转下月(h)" width="96" align="right">
                        <template slot-scope="{ row: r }">
                          <span :class="r.closing > 0 ? 'warn-text' : 'muted'">{{ hours(r.closing) }}</span>
                        </template>
                      </el-table-column>
                      <el-table-column label="提成" width="108" align="right">
                        <template slot-scope="{ row: r }">
                          <b :class="Number(r.commission) < 0 ? 'bad-text' : Number(r.commission) > 0 ? 'ok-text' : 'muted'">
                            {{ moneySymbol(r.commission) }}
                          </b>
                        </template>
                      </el-table-column>
                    </el-table>
                  </div>
                </template>
              </el-table-column>

              <el-table-column label="人员" min-width="155">
                <template slot-scope="{ row }">
                  <div class="emp-cell">
                    <span class="emp-avatar">{{ (row.employee_name || '工').slice(0, 1) }}</span>
                    <span class="emp-name">{{ row.employee_name }}</span>
                    <el-tag size="mini" type="info" effect="plain" class="emp-wo-tag">{{ (row.rows || []).length }} 项</el-tag>
                  </div>
                </template>
              </el-table-column>
              <el-table-column label="上月结转（小时）" min-width="135" align="right">
                <template slot-scope="{ row }">{{ hours(row.opening) }}</template>
              </el-table-column>
              <el-table-column label="本月新做（小时）" min-width="130" align="right">
                <template slot-scope="{ row }">{{ hours(row.new) }}</template>
              </el-table-column>
              <el-table-column label="本月出库结算（小时）" min-width="145" align="right">
                <template slot-scope="{ row }">
                  <b :class="row.settled < 0 ? 'bad-text' : 'ok-text'">{{ hours(row.settled) }}</b>
                </template>
              </el-table-column>
              <el-table-column label="本月报废（小时）" min-width="125" align="right">
                <template slot-scope="{ row }">
                  <span :class="row.scrap > 0 ? 'bad-text' : 'muted'">{{ hours(row.scrap) }}</span>
                </template>
              </el-table-column>
              <el-table-column label="结转下月（小时）" min-width="135" align="right">
                <template slot-scope="{ row }">
                  <b :class="row.closing > 0 ? 'warn-text' : 'muted'">{{ hours(row.closing) }}</b>
                </template>
              </el-table-column>
              <el-table-column label="本月结算提成" min-width="135" align="right">
                <template slot-scope="{ row }">
                  <b :class="Number(row.commission) < 0 ? 'bad-text' : 'ok-text'">{{ moneySymbol(row.commission) }}</b>
                </template>
              </el-table-column>
              <el-table-column label="操作" width="110" align="center">
                <template slot-scope="{ row }">
                  <el-button type="text" size="small" class="action-btn-green" @click.stop="toggleMonthRow(row)">
                    {{ isMonthRowExpanded(row) ? '收起明细' : '工单明细' }}
                  </el-button>
                </template>
              </el-table-column>
            </el-table>
          </template>
        </el-tab-pane>

        <!-- ==================== Tab 2: 本月生产明细 ==================== -->
        <el-tab-pane name="production">
          <span slot="label" class="tab-label-wrap">
            <i class="el-icon-user" />
            <span>本月生产明细</span>
            <span v-if="prodData && prodData.employees" class="tab-count-pill">{{ prodData.employees.length }}</span>
          </span>

          <div class="perf-toolbar">
            <div class="toolbar-left">
              <span class="filter-label">生产月份</span>
              <el-date-picker
                v-model="month"
                type="month"
                value-format="yyyy-MM"
                size="small"
                :clearable="false"
                placeholder="选择月份"
                style="width: 145px"
                @change="loadProduction"
              />
              <el-radio-group v-model="prodKind" size="small" @change="loadProduction">
                <el-radio-button label="">全部类别</el-radio-button>
                <el-radio-button label="finished">只看成品</el-radio-button>
                <el-radio-button label="semi">半成品 / 集中开料</el-radio-button>
                <el-radio-button label="packing">试水/包装</el-radio-button>
              </el-radio-group>
              <el-input
                v-model="prodKeyword"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                placeholder="筛选人员姓名 / 工单号 / 产品"
                style="width: 220px"
              />
              <el-button size="small" type="success" class="btn-theme-create" icon="el-icon-search" :loading="prodLoading" @click="loadProduction">查询</el-button>
              <el-button size="small" icon="el-icon-refresh-left" @click="resetProdFilter">重置</el-button>
            </div>
            <div class="toolbar-right">
              <el-button
                v-if="prodPeople.length"
                size="small"
                plain
                :icon="prodAllExpanded ? 'el-icon-arrow-up' : 'el-icon-arrow-down'"
                @click="toggleExpandAllProd"
              >{{ prodAllExpanded ? '收起全部明细' : '展开全部明细' }}</el-button>
            </div>
          </div>

          <template v-if="prodData">
            <!-- 本月生产 4 宫格汇总指标卡 -->
            <div class="perf-kpi-grid">
              <div class="perf-kpi-card">
                <div class="kpi-title">参与生产人数</div>
                <div class="kpi-value">{{ prodPeople.length }} <small>人</small></div>
                <div class="kpi-desc">按干活时间统计（不论是否出库）</div>
              </div>
              <div class="perf-kpi-card is-green">
                <div class="kpi-title">参与完工成品累计</div>
                <div class="kpi-value">{{ prodSummary.finishedUnits }} <small>台次</small></div>
                <div class="kpi-desc">多人协作同一工单各自记参与台数</div>
              </div>
              <div class="perf-kpi-card is-amber">
                <div class="kpi-title">半成品 / 集中开料产出</div>
                <div class="kpi-value">{{ prodSummary.semiUnits }} <small>件</small> / {{ prodSummary.cutPieces }} <small>件</small></div>
                <div class="kpi-desc">电箱、架子半成品与集中开料件数</div>
              </div>
              <div class="perf-kpi-card">
                <div class="kpi-title">本月登记总工时</div>
                <div class="kpi-value">{{ hours(prodSummary.monthMinutes) }} <small>小时</small></div>
                <div class="kpi-desc">合 {{ prodSummary.monthMinutes }} 分钟实际报工</div>
              </div>
            </div>

            <el-table
              v-loading="prodLoading"
              :data="prodPeople"
              size="small"
              border
              row-key="employee_legacy_id"
              :expand-row-keys="prodExpandedKeys"
              empty-text="当前月份暂无生产报工记录"
              class="perf-table clickable"
              @row-click="toggleProdRow"
            >
              <el-table-column type="expand" width="44">
                <template slot-scope="{ row }">
                  <div class="sub-detail-panel">
                    <div class="sub-detail-head">
                      <span class="sub-detail-title">
                        <i class="el-icon-s-cooperation" />
                        {{ row.employee_name }} · {{ month }} 生产参与明细
                      </span>
                      <span class="sub-detail-meta">本人每台工时 = 本人累计工时 ÷ 工单投入台数；工单每台工时 = 全部人累计工时 ÷ 投入台数</span>
                    </div>
                    <el-table :data="row.rows" size="mini" border class="inner-sub-table">
                      <el-table-column label="类型" width="95" align="center">
                        <template slot-scope="{ row: r }">
                          <el-tag
                            size="mini"
                            :type="r.class === 'finished' ? 'success' : (r.class === 'packing' ? 'info' : 'warning')"
                            effect="plain"
                          >{{ r.class_label }}</el-tag>
                        </template>
                      </el-table-column>
                      <el-table-column label="工单 / 批次与参与工序" min-width="220">
                        <template slot-scope="{ row: r }">
                          <div class="wo-cell-main">
                            <router-link v-if="r.work_order_id" :to="`/production/work-orders/${r.work_order_id}`" class="wo-link" @click.native.stop>{{ r.work_order_no }}</router-link>
                            <span v-else class="mono-bold">{{ r.work_order_no }}</span>
                            <span v-if="r.sales_order_no" class="muted"> · {{ r.sales_order_no }}</span>
                          </div>
                          <div class="muted" style="margin-top: 2px">{{ r.item_name }} · {{ (r.operations || []).join('、') }}</div>
                        </template>
                      </el-table-column>
                      <el-table-column label="本月完工 / 工单数量" width="145" align="right">
                        <template slot-scope="{ row: r }">
                          <template v-if="r.running"><span class="warn-text">开料进行中</span></template>
                          <template v-else><b>{{ qty(r.completed_in_month) }}</b> / {{ qty(r.base_qty) }} {{ r.unit_name }}</template>
                        </template>
                      </el-table-column>
                      <el-table-column label="本人本月工时(h)" width="120" align="right">
                        <template slot-scope="{ row: r }"><b class="ok-text">{{ hours(r.my_month_minutes) }}</b></template>
                      </el-table-column>
                      <el-table-column label="本人累计工时(h)" width="120" align="right">
                        <template slot-scope="{ row: r }">{{ hours(r.my_total_minutes) }}</template>
                      </el-table-column>
                      <el-table-column label="本人每台工时(h)" width="120" align="right">
                        <template slot-scope="{ row: r }"><b>{{ hours(r.my_minutes_per_unit) }}</b></template>
                      </el-table-column>
                      <el-table-column label="工单每台工时（全部人）" width="160" align="right">
                        <template slot-scope="{ row: r }">{{ hours(r.wo_minutes_per_unit) }}</template>
                      </el-table-column>
                      <el-table-column label="当前进度说明" min-width="165">
                        <template slot-scope="{ row: r }">
                          <span v-if="r.running" class="warn-text">开料还没结束，件数结束后计入</span>
                          <span v-else-if="r.finished" class="ok-text">已全部做完</span>
                          <span v-else class="warn-text">已完工 {{ qty(r.completed_total) }}，每台工时还会变</span>
                        </template>
                      </el-table-column>
                    </el-table>
                  </div>
                </template>
              </el-table-column>

              <el-table-column label="人员" min-width="155">
                <template slot-scope="{ row }">
                  <div class="emp-cell">
                    <span class="emp-avatar">{{ (row.employee_name || '工').slice(0, 1) }}</span>
                    <span class="emp-name">{{ row.employee_name }}</span>
                  </div>
                </template>
              </el-table-column>
              <el-table-column label="本月完工成品（台）" min-width="140" align="right">
                <template slot-scope="{ row }"><b class="ok-text">{{ qty(row.finished_units) }}</b></template>
              </el-table-column>
              <el-table-column label="半成品（件）" min-width="120" align="right">
                <template slot-scope="{ row }">{{ qty(row.semi_units) || '—' }}</template>
              </el-table-column>
              <el-table-column label="集中开料（件）" min-width="125" align="right">
                <template slot-scope="{ row }">{{ qty(row.cut_pieces) || '—' }}</template>
              </el-table-column>
              <el-table-column label="试水/包装（道）" min-width="120" align="right">
                <template slot-scope="{ row }">{{ row.packing_count || '—' }}</template>
              </el-table-column>
              <el-table-column label="本月工时（小时）" min-width="135" align="right">
                <template slot-scope="{ row }"><b class="ok-text">{{ hours(row.month_minutes) }}</b></template>
              </el-table-column>
              <el-table-column prop="work_order_count" label="参与工单数" width="110" align="right" />
              <el-table-column label="操作" width="110" align="center">
                <template slot-scope="{ row }">
                  <el-button type="text" size="small" class="action-btn-green" @click.stop="toggleProdRow(row)">
                    {{ isProdRowExpanded(row) ? '收起明细' : '查看明细' }}
                  </el-button>
                </template>
              </el-table-column>
            </el-table>
          </template>
        </el-tab-pane>

        <!-- ==================== Tab 3: 工时去向（按工单） ==================== -->
        <el-tab-pane name="wos">
          <span slot="label" class="tab-label-wrap">
            <i class="el-icon-s-order" />
            <span>工时去向（按工单）</span>
            <span v-if="woTotal" class="tab-count-pill">{{ woTotal }}</span>
          </span>

          <!-- 工单工时汇总 4 宫格指标卡 -->
          <div v-if="woSummary" class="perf-kpi-grid">
            <div class="perf-kpi-card" :class="{ 'is-active-filter': !onlyUnsettled }" @click="toggleOnlyUnsettled(false)">
              <div class="kpi-title">筛选范围合计总工时</div>
              <div class="kpi-value">{{ hours(woSummary.labor_minutes) }} <small>小时</small></div>
              <div class="kpi-desc">点击查看全部工单与开料批次</div>
            </div>
            <div class="perf-kpi-card is-green">
              <div class="kpi-title">已随出库结算工时</div>
              <div class="kpi-value">{{ hours(woSummary.settled_minutes) }} <small>小时</small></div>
              <div class="kpi-desc">对应产品或所装整机已发货</div>
            </div>
            <div class="perf-kpi-card is-danger">
              <div class="kpi-title">报废工时（单列）</div>
              <div class="kpi-value">{{ hours(woSummary.scrap_minutes) }} <small>小时</small></div>
              <div class="kpi-desc">报废审批当月核销，不进订单</div>
            </div>
            <div class="perf-kpi-card is-amber" :class="{ 'is-active-filter': onlyUnsettled }" @click="toggleOnlyUnsettled(true)">
              <div class="kpi-title">未结算工时（待出库）</div>
              <div class="kpi-value">{{ hours(woSummary.unsettled_minutes) }} <small>小时</small></div>
              <div class="kpi-desc">点击只看还有未结算工时的工单</div>
            </div>
          </div>

          <div class="perf-toolbar">
            <div class="toolbar-left">
              <el-input
                v-model="woKeyword"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                placeholder="搜索工单号 / 开料单号 / 产品 / 订单号"
                style="width: 260px"
                @keyup.enter.native="searchWos"
                @clear="searchWos"
              />
              <el-checkbox v-model="onlyUnsettled" @change="searchWos">只看还有未结算的</el-checkbox>
              <el-button size="small" type="success" class="btn-theme-create" icon="el-icon-search" @click="searchWos">查询</el-button>
              <el-button size="small" icon="el-icon-refresh-left" @click="resetWosFilter">重置</el-button>
            </div>
          </div>

          <el-table v-loading="woLoading" :data="wos" size="small" border stripe class="perf-table">
            <el-table-column label="工单 / 开料单号" min-width="175">
              <template slot-scope="{ row }">
                <div class="wo-cell-main">
                  <router-link v-if="row.work_order_id" :to="`/production/work-orders/${row.work_order_id}`" class="wo-link">{{ row.work_order_no }}</router-link>
                  <span v-else class="mono-bold">{{ row.work_order_no }}</span>
                  <el-tag size="mini" :type="sourceTagType(row.source_label)" effect="plain">{{ row.source_label }}</el-tag>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="产品 / 关联订单" min-width="170" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <div class="item-primary">{{ row.item_name }}</div>
                <div class="item-sub muted">{{ row.sales_order_no ? `订单：${row.sales_order_no}` : '库存备货 / 公共开料' }}</div>
              </template>
            </el-table-column>
            <el-table-column label="出库状态" width="110" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="statusType(row.status_label)" effect="plain">{{ row.status_label }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="产出与实物去向分布" min-width="260">
              <template slot-scope="{ row }">
                <div class="dest-pills">
                  <span class="dest-pill">产出 <b>{{ row.output_qty }}</b></span>
                  <span class="dest-pill is-ok">已出库 <b>{{ row.shipped_qty }}</b></span>
                  <span v-if="row.consumed_pending_qty" class="dest-pill is-warn">装机/领用待出 <b>{{ row.consumed_pending_qty }}</b></span>
                  <span v-if="row.in_stock_qty" class="dest-pill">在库 <b>{{ row.in_stock_qty }}</b></span>
                  <span v-if="row.other_qty" class="dest-pill is-muted">未完/待领 <b>{{ row.other_qty }}</b></span>
                  <span v-if="row.scrapped_qty" class="dest-pill is-danger">报废 <b>{{ row.scrapped_qty }}</b></span>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="总工时(h)" width="105" align="right">
              <template slot-scope="{ row }">{{ hours(row.labor_minutes) }}</template>
            </el-table-column>
            <el-table-column label="已结算(h)" width="105" align="right">
              <template slot-scope="{ row }"><b class="ok-text">{{ hours(row.settled_minutes) }}</b></template>
            </el-table-column>
            <el-table-column label="报废工时(h)" width="100" align="right">
              <template slot-scope="{ row }">
                <span :class="row.scrap_minutes > 0 ? 'bad-text' : 'muted'">{{ row.scrap_minutes ? hours(row.scrap_minutes) : '—' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="未结算(h)" width="105" align="right">
              <template slot-scope="{ row }">
                <b :class="row.unsettled_minutes > 0 ? 'warn-text' : 'muted'">{{ hours(row.unsettled_minutes) }}</b>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="100" align="center">
              <template slot-scope="{ row }">
                <el-button type="text" size="small" class="action-btn-green" icon="el-icon-connection" @click="showBatch(row.work_order_no)">查看去向</el-button>
              </template>
            </el-table-column>
          </el-table>

          <div class="pager">
            <el-pagination
              background
              layout="total, sizes, prev, pager, next, jumper"
              :total="woTotal"
              :page-size="woPerPage"
              :page-sizes="[10, 20, 50]"
              :current-page.sync="woPage"
              @current-change="loadWos"
              @size-change="onWoSizeChange"
            />
          </div>
        </el-tab-pane>

        <!-- ==================== Tab 4: 订单统计 ==================== -->
        <el-tab-pane name="orders">
          <span slot="label" class="tab-label-wrap">
            <i class="el-icon-tickets" />
            <span>订单统计</span>
            <span v-if="total" class="tab-count-pill">{{ total }}</span>
          </span>

          <!-- 当前页订单统计概览卡 -->
          <div class="perf-kpi-grid">
            <div class="perf-kpi-card" :class="{ 'is-active-filter': orderStatusFilter === '' }" @click="orderStatusFilter = ''">
              <div class="kpi-title">关联生产/发货订单总数</div>
              <div class="kpi-value">{{ total }} <small>单</small></div>
              <div class="kpi-desc">当前页展示 {{ filteredOrders.length }} 单（点击显示全部）</div>
            </div>
            <div class="perf-kpi-card is-green" :class="{ 'is-active-filter': orderStatusFilter === 'settleable' }" @click="orderStatusFilter = orderStatusFilter === 'settleable' ? '' : 'settleable'">
              <div class="kpi-title">本页已发货可结算</div>
              <div class="kpi-value">{{ orderPageSummary.settleableCount }} <small>单</small></div>
              <div class="kpi-desc">订单商品已全部发运完成</div>
            </div>
            <div class="perf-kpi-card is-amber" :class="{ 'is-active-filter': orderStatusFilter === 'estimated' }" @click="orderStatusFilter = orderStatusFilter === 'estimated' ? '' : 'estimated'">
              <div class="kpi-title">本页待出库预估</div>
              <div class="kpi-value">{{ orderPageSummary.estimatedCount }} <small>单</small></div>
              <div class="kpi-desc">仍在生产或部分发货</div>
            </div>
            <div class="perf-kpi-card">
              <div class="kpi-title">本页订单归集总工时</div>
              <div class="kpi-value">{{ hours(orderPageSummary.totalMinutes) }} <small>小时</small></div>
              <div class="kpi-desc">含订单工单、半成品与备货整机</div>
            </div>
          </div>

          <div class="perf-toolbar">
            <div class="toolbar-left">
              <el-input
                v-model="keyword"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                placeholder="搜索订单号 / 客户名称"
                style="width: 240px"
                @keyup.enter.native="search"
                @clear="search"
              />
              <el-select v-model="orderStatusFilter" size="small" placeholder="本页状态筛选" clearable style="width: 170px">
                <el-option label="全部状态" value="" />
                <el-option label="已全部发货（可结算）" value="settleable" />
                <el-option label="待出库（预估）" value="estimated" />
                <el-option label="含待处理提醒" value="issues" />
              </el-select>
              <el-button size="small" type="success" class="btn-theme-create" icon="el-icon-search" @click="search">查询</el-button>
              <el-button size="small" icon="el-icon-refresh-left" @click="resetOrderFilter">重置</el-button>
            </div>
          </div>

          <el-table
            v-loading="loading"
            :data="filteredOrders"
            size="small"
            border
            stripe
            highlight-current-row
            class="perf-table clickable"
            @row-click="openOrder"
          >
            <el-table-column prop="sales_order_no" label="订单号" min-width="155">
              <template slot-scope="{ row }">
                <span class="mono-bold">{{ row.sales_order_no }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="customer_name" label="客户名称" min-width="150" show-overflow-tooltip />
            <el-table-column prop="order_date" label="下单日期" width="115">
              <template slot-scope="{ row }">{{ row.order_date || '—' }}</template>
            </el-table-column>
            <el-table-column label="结算状态" width="165" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="row.status === 'settleable' ? 'success' : 'warning'" effect="plain">
                  {{ row.status_label }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="商品金额" width="130" align="right">
              <template slot-scope="{ row }">{{ moneySymbol(row.goods_amount) }}</template>
            </el-table-column>
            <el-table-column label="提成合计" width="130" align="right">
              <template slot-scope="{ row }"><b class="ok-text">{{ moneySymbol(row.performance_amount) }}</b></template>
            </el-table-column>
            <el-table-column label="工时（小时）" width="115" align="right">
              <template slot-scope="{ row }">{{ hours(row.labor_minutes) }}</template>
            </el-table-column>
            <el-table-column prop="employee_count" label="参与人数" width="90" align="right">
              <template slot-scope="{ row }">{{ row.employee_count }} 人</template>
            </el-table-column>
            <el-table-column label="待处理提醒" width="105" align="center">
              <template slot-scope="{ row }">
                <el-tag v-if="row.issue_count" size="mini" type="warning" effect="plain">{{ row.issue_count }} 项</el-tag>
                <span v-else class="muted">—</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="135" align="center">
              <template slot-scope="{ row }">
                <el-button type="text" size="small" class="action-btn-green" icon="el-icon-document-checked" @click.stop="openOrder(row)">
                  提成与工时明细
                </el-button>
              </template>
            </el-table-column>
          </el-table>

          <div class="pager">
            <el-pagination
              background
              layout="total, sizes, prev, pager, next, jumper"
              :total="total"
              :page-size="orderPerPage"
              :page-sizes="[10, 20, 30]"
              :current-page.sync="page"
              @current-change="loadOrders"
              @size-change="onOrderSizeChange"
            />
          </div>
        </el-tab-pane>

        <!-- ==================== Tab 5: 备货/开料批次去向 ==================== -->
        <el-tab-pane name="batch">
          <span slot="label" class="tab-label-wrap">
            <i class="el-icon-connection" />
            <span>备货/开料批次去向</span>
          </span>

          <div class="perf-toolbar">
            <div class="toolbar-left">
              <el-input
                v-model="batchNo"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                placeholder="输入工单号（= 库存批次号）或开料记录号 MCR…"
                style="width: 290px"
                @keyup.enter.native="loadBatch"
              />
              <el-button size="small" type="success" class="btn-theme-create" icon="el-icon-search" :loading="batchLoading" @click="loadBatch">查询去向</el-button>
              <el-button v-if="batch" size="small" icon="el-icon-refresh-left" @click="clearBatch">清空结果</el-button>
            </div>
          </div>

          <!-- 快捷选择近期工单/开料批次 -->
          <div v-if="wos.length" class="quick-batch-bar">
            <span class="quick-batch-label"><i class="el-icon-collection-tag" /> 快捷选择工单/开料批次：</span>
            <el-tag
              v-for="item in wos.slice(0, 8)"
              :key="item.work_order_no"
              size="small"
              :type="batch && batch.work_order && batch.work_order.work_order_no === item.work_order_no ? 'success' : 'info'"
              :effect="batch && batch.work_order && batch.work_order.work_order_no === item.work_order_no ? 'dark' : 'plain'"
              class="quick-batch-tag"
              @click="showBatch(item.work_order_no)"
            >
              {{ item.work_order_no }} · {{ item.item_name }}
            </el-tag>
          </div>

          <template v-if="batch && batch.work_order">
            <div class="batch-overview-grid">
              <div class="batch-overview-cell">
                <div class="cell-label">工单 / 批次单号</div>
                <div class="cell-main">
                  <span class="mono-bold">{{ batch.work_order.work_order_no }}</span>
                  <el-tag size="mini" :type="statusType(batch.work_order.status_label)" effect="plain" style="margin-left: 6px">
                    {{ batch.work_order.status_label }}
                  </el-tag>
                </div>
                <div class="cell-sub">{{ batch.work_order.source_label }} · {{ batch.work_order.item_name }}</div>
              </div>
              <div class="batch-overview-cell">
                <div class="cell-label">产出与出库进度（{{ batch.work_order.unit_name }}）</div>
                <div class="cell-main">
                  产出 <b>{{ batch.work_order.output_qty }}</b> ·
                  <span class="ok-text">已出库 {{ batch.work_order.shipped_qty }}</span>
                </div>
                <div class="cell-sub">
                  <span class="warn-text">未出库 {{ batch.work_order.pending_qty }} {{ batch.work_order.unit_name }}</span>
                  <span v-if="batch.work_order.scrapped_qty" class="bad-text"> · 报废 {{ batch.work_order.scrapped_qty }} 件</span>
                </div>
              </div>
              <div class="batch-overview-cell">
                <div class="cell-label">整批总工时 / 单件工时</div>
                <div class="cell-main">{{ hours(batch.work_order.labor_minutes) }} 小时</div>
                <div class="cell-sub">
                  <template v-if="batch.work_order.labor_minutes_per_unit !== null">
                    每{{ batch.work_order.unit_name || '件' }}：{{ batch.work_order.kind === 'cut' ? batch.work_order.labor_minutes_per_unit + ' 分钟' : hours(batch.work_order.labor_minutes_per_unit) + ' 小时' }}
                  </template>
                  <template v-else>—</template>
                </div>
              </div>
              <div class="batch-overview-cell is-green">
                <div class="cell-label">已结算 / 未结算工时</div>
                <div class="cell-main">
                  <span class="ok-text">已结 {{ hours(batch.work_order.settled_minutes) }}h</span> ·
                  <span class="warn-text">未结 {{ hours(batch.work_order.unsettled_minutes) }}h</span>
                </div>
                <div class="cell-sub">
                  <span v-if="batch.work_order.scrapped_qty" class="bad-text">报废工时 {{ hours(batch.work_order.scrap_minutes) }}h（单列不摊合格品）</span>
                  <span v-else>随产品或所装整机出库按比例结算</span>
                </div>
              </div>
            </div>

            <el-table :data="batch.usage" size="small" border empty-text="这批还没有被领用或发货" class="perf-table">
              <el-table-column prop="type_label" label="去向类型" min-width="140" />
              <el-table-column label="去向工单 / 发货单" min-width="160">
                <template slot-scope="{ row }">
                  <router-link v-if="row.target_work_order_id" :to="`/production/work-orders/${row.target_work_order_id}`" class="wo-link">{{ row.target }}</router-link>
                  <span v-else class="mono-bold">{{ row.target }}</span>
                </template>
              </el-table-column>
              <el-table-column label="关联订单" min-width="145">
                <template slot-scope="{ row }">
                  {{ row.sales_order_no || (row.type === 'consumed' ? '备货（未关联订单）' : '—') }}
                </template>
              </el-table-column>
              <el-table-column prop="qty" label="数量" width="90" align="right" />
              <el-table-column label="出库结算情况" min-width="190">
                <template slot-scope="{ row }">
                  <el-tag size="mini" :type="row.shipped ? 'success' : (row.shipped_qty > 0 ? 'warning' : 'info')" effect="plain">
                    {{ row.status_label }}
                  </el-tag>
                  <span v-if="row.date" class="muted"> {{ row.date }}</span>
                  <span v-if="row.type === 'consumed' && row.shipped_qty > 0 && row.pending_qty > 0" class="muted">
                    （已出 {{ row.shipped_qty }}，待出 {{ row.pending_qty }}）
                  </span>
                </template>
              </el-table-column>
              <el-table-column label="对应工时（小时）" width="140" align="right">
                <template slot-scope="{ row }"><b>{{ hours(row.labor_minutes) }}</b></template>
              </el-table-column>
            </el-table>
          </template>

          <el-empty v-else description="请输入工单号或开料记录号查询，或点击上方快捷标签查看产出去向" :image-size="90" />
        </el-tab-pane>
      </el-tabs>
    </div>

    <!-- ==================== 订单提成与工时明细弹窗（替代原侧滑抽屉） ==================== -->
    <el-dialog
      :visible.sync="drawer"
      :title="detail ? `订单 ${detail.order.sales_order_no} · 提成与工时核算明细` : '订单提成与工时核算明细'"
      width="1080px"
      top="4vh"
      custom-class="master-style-dialog perf-order-dialog"
      :close-on-click-modal="false"
      append-to-body
    >
      <div v-loading="detailLoading" class="detail-dialog-body">
        <template v-if="detail">
          <!-- 顶部 5 宫格指标卡 -->
          <div class="dialog-kpi-bar">
            <div class="dialog-kpi-item">
              <div class="d-kpi-label">客户名称 / 订单号</div>
              <div class="d-kpi-val">{{ detail.order.customer_name || '—' }}</div>
              <div class="d-kpi-sub">{{ detail.order.sales_order_no }}</div>
            </div>
            <div class="dialog-kpi-item">
              <div class="d-kpi-label">当前结算状态</div>
              <div class="d-kpi-val">
                <el-tag size="small" :type="detail.status === 'settleable' ? 'success' : 'warning'" effect="plain">
                  {{ detail.status_label }}
                </el-tag>
              </div>
              <div class="d-kpi-sub">全部发完货才算正式可结算</div>
            </div>
            <div class="dialog-kpi-item">
              <div class="d-kpi-label">订单商品折后金额</div>
              <div class="d-kpi-val">{{ moneySymbol(detail.totals.goods_amount) }}</div>
              <div class="d-kpi-sub">
                <span v-if="detail.order.exchange_rate">业务汇率 {{ detail.order.exchange_rate }}</span>
                <span v-else>提成比例核算基数</span>
              </div>
            </div>
            <div class="dialog-kpi-item is-green">
              <div class="d-kpi-label">订单提成合计</div>
              <div class="d-kpi-val ok-text">{{ moneySymbol(detail.totals.performance_amount) }}</div>
              <div class="d-kpi-sub">含整机、半成品与发货包装</div>
            </div>
            <div class="dialog-kpi-item">
              <div class="d-kpi-label">本单归集总工时</div>
              <div class="d-kpi-val">{{ hours(detail.totals.labor_minutes) }} 小时</div>
              <div class="d-kpi-sub">共 {{ (detail.employees || []).length }} 名员工参与</div>
            </div>
          </div>

          <el-alert
            v-for="(text, i) in detail.issues"
            :key="'i' + i"
            :title="text"
            type="warning"
            :closable="false"
            show-icon
            class="issue-alert"
          />

          <div class="dialog-sec-head">
            <span class="sec-bar" />
            <span class="sec-txt">按人汇总（共 {{ (detail.employees || []).length }} 人）</span>
          </div>
          <el-table :data="detail.employees" size="small" border empty-text="还没有报工记录" class="perf-table mb-14">
            <el-table-column prop="employee_name" label="人员姓名" min-width="140">
              <template slot-scope="{ row }"><b>{{ row.employee_name }}</b></template>
            </el-table-column>
            <el-table-column prop="operations" label="参与工序数" width="120" align="right" />
            <el-table-column label="本单工时（小时）" width="150" align="right">
              <template slot-scope="{ row }">{{ hours(row.labor_minutes) }}</template>
            </el-table-column>
            <el-table-column label="应得提成" width="150" align="right">
              <template slot-scope="{ row }"><b class="ok-text">{{ moneySymbol(row.performance_amount) }}</b></template>
            </el-table-column>
          </el-table>

          <div class="dialog-sec-head">
            <span class="sec-bar" />
            <span class="sec-txt">按订单商品行追溯（整机工单、库存半成品与发货包装明细）</span>
          </div>
          <div v-for="line in detail.lines" :key="line.sales_order_line_id" class="line-box">
            <div class="line-head">
              <div class="line-head-left">
                <span class="line-badge">订单商品</span>
                <b>{{ line.product_name || line.item_name }}</b>
                <span v-if="line.sku_name" class="muted">· {{ line.sku_name }}</span>
              </div>
              <div class="line-head-right">
                <span>数量：<b>{{ line.qty }}</b></span>
                <span>金额：<b>{{ moneySymbol(line.amount) }}</b></span>
                <span class="ok-text">已出库：<b>{{ line.shipped_qty }}</b></span>
                <span v-if="line.pending_qty > 0" class="warn-text">未出库：<b>{{ line.pending_qty }}</b>（预估）</span>
                <span v-if="line.returned_qty > 0" class="bad-text">退货：<b>{{ line.returned_qty }}</b>（仅记录）</span>
              </div>
            </div>

            <el-table :data="flatten(line)" size="mini" border :row-class-name="rowClass" class="perf-table">
              <el-table-column label="来源工单 / 工序" min-width="235">
                <template slot-scope="{ row }">
                  <div v-if="row.kind === 'wo'" :style="{ paddingLeft: row.level * 18 + 'px' }">
                    <i :class="row.level ? 'el-icon-share' : 'el-icon-s-order'" />
                    <router-link v-if="row.work_order_id" :to="`/production/work-orders/${row.work_order_id}`" class="wo-link" style="margin-left: 4px">
                      {{ row.work_order_no }}
                    </router-link>
                    <span v-else style="margin-left: 4px">{{ row.batch_no || '无批次' }}</span>
                    <el-tag size="mini" effect="plain" class="tag">{{ row.source_label }}</el-tag>
                    <span class="muted">{{ row.item_name }}</span>
                  </div>
                  <div v-else-if="row.kind === 'note'" :style="{ paddingLeft: row.level * 18 + 18 + 'px' }" class="note">{{ row.text }}</div>
                  <div v-else :style="{ paddingLeft: row.level * 18 + 18 + 'px' }">
                    <b>{{ row.operation_name }}</b>
                    <span v-if="row.shipment_no" class="muted">（{{ row.shipment_no }}）</span>
                  </div>
                </template>
              </el-table-column>
              <el-table-column label="用量 / 产出" width="115" align="right">
                <template slot-scope="{ row }">
                  <span v-if="row.kind === 'wo' && row.output_qty !== undefined">{{ row.used_qty }} / {{ row.output_qty }}</span>
                </template>
              </el-table-column>
              <el-table-column label="提成比例" width="90" align="right">
                <template slot-scope="{ row }">
                  <span v-if="row.kind === 'op'">{{ row.performance_rate === null ? '不计' : pct(row.performance_rate, 2) }}</span>
                </template>
              </el-table-column>
              <el-table-column label="工序提成" width="110" align="right">
                <template slot-scope="{ row }">
                  <b v-if="row.kind === 'op'" class="ok-text">{{ moneySymbol(row.performance_pool_amount) }}</b>
                </template>
              </el-table-column>
              <el-table-column label="本单工时 / 这批总工时(h)" width="185" align="right">
                <template slot-scope="{ row }">
                  <span v-if="row.kind === 'op'">{{ hours(row.labor_minutes) }} / {{ hours(row.labor_minutes_total) }}</span>
                </template>
              </el-table-column>
              <el-table-column label="分到人（份额 · 提成 · 工时）" min-width="255">
                <template slot-scope="{ row }">
                  <span v-if="row.kind === 'op'">
                    <span v-for="e in row.employees" :key="e.employee_legacy_id" class="emp">
                      <b>{{ e.employee_name }}</b> {{ pct(e.share_ratio) }}<template v-if="e.performance_amount !== null"> <b class="ok-text">¥{{ money(e.performance_amount) }}</b></template> · {{ hours(e.labor_minutes) }}h
                    </span>
                    <span v-if="!row.employees.length" class="muted">还没有报工</span>
                    <el-tag v-if="row.employees.length" size="mini" effect="plain" class="tag">
                      {{ row.share_mode === 'fixed' ? '固定份额' : '按工时分' }}
                    </el-tag>
                  </span>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </template>
      </div>

      <span slot="footer" class="dialog-footer">
        <el-button size="small" @click="drawer = false">关 闭</el-button>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { listPerformanceOrders, getPerformanceOrder, getPerformanceBatch, getPerformanceMonth, getPerformanceProduction, listPerformanceWorkOrders } from '../../../api/erp/production'

const currentMonth = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

const TAB_LABELS = {
  month: '月度结算台账',
  production: '本月生产明细',
  wos: '工时去向（按工单）',
  orders: '订单提成与工时统计',
  batch: '备货/开料批次去向',
}

export default {
  name: 'ProductionPerformanceStats',
  data() {
    const month = currentMonth()
    return {
      tab: 'month',
      month,
      monthKeyword: '',
      monthData: null,
      monthLoading: false,
      monthExpandedKeys: [],
      prodData: null,
      prodLoading: false,
      prodKind: '',
      prodKeyword: '',
      prodExpandedKeys: [],
      wos: [],
      woTotal: 0,
      woPage: 1,
      woPerPage: 10,
      woKeyword: '',
      onlyUnsettled: true,
      woLoading: false,
      woSummary: null,
      woLoaded: false,
      keyword: '',
      orderStatusFilter: '',
      orders: [],
      total: 0,
      page: 1,
      orderPerPage: 10,
      loading: false,
      drawer: false,
      detail: null,
      detailLoading: false,
      batchNo: '',
      batch: null,
      batchLoading: false,
    }
  },
  computed: {
    activeTabLabel() {
      return TAB_LABELS[this.tab] || '提成与工时统计'
    },
    filteredMonthEmployees() {
      const list = (this.monthData && this.monthData.employees) || []
      const kw = (this.monthKeyword || '').trim().toLowerCase()
      if (!kw) return list
      return list.filter(emp => {
        if ((emp.employee_name || '').toLowerCase().includes(kw)) return true
        return (emp.rows || []).some(r =>
          (r.work_order_no || '').toLowerCase().includes(kw) ||
          (r.item_name || '').toLowerCase().includes(kw)
        )
      })
    },
    monthAllExpanded() {
      const list = this.filteredMonthEmployees
      return list.length > 0 && list.every(e => this.monthExpandedKeys.includes(e.employee_legacy_id))
    },
    prodPeople() {
      const kw = (this.prodKeyword || '').trim().toLowerCase()
      const list = (this.prodData && this.prodData.employees) || []
      if (!kw) return list
      return list.filter(emp => {
        if ((emp.employee_name || '').toLowerCase().includes(kw)) return true
        return (emp.rows || []).some(r =>
          (r.work_order_no || '').toLowerCase().includes(kw) ||
          (r.item_name || '').toLowerCase().includes(kw)
        )
      })
    },
    prodAllExpanded() {
      const list = this.prodPeople
      return list.length > 0 && list.every(e => this.prodExpandedKeys.includes(e.employee_legacy_id))
    },
    prodSummary() {
      const list = this.prodPeople
      let finishedUnits = 0
      let semiUnits = 0
      let cutPieces = 0
      let monthMinutes = 0
      list.forEach(emp => {
        finishedUnits += Number(emp.finished_units || 0)
        semiUnits += Number(emp.semi_units || 0)
        cutPieces += Number(emp.cut_pieces || 0)
        monthMinutes += Number(emp.month_minutes || 0)
      })
      return {
        finishedUnits: this.qty(finishedUnits),
        semiUnits: this.qty(semiUnits),
        cutPieces: this.qty(cutPieces),
        monthMinutes: Math.round(monthMinutes * 10) / 10,
      }
    },
    filteredOrders() {
      const list = this.orders || []
      if (!this.orderStatusFilter) return list
      if (this.orderStatusFilter === 'settleable') return list.filter(r => r.status === 'settleable')
      if (this.orderStatusFilter === 'estimated') return list.filter(r => r.status !== 'settleable')
      if (this.orderStatusFilter === 'issues') return list.filter(r => Number(r.issue_count || 0) > 0)
      return list
    },
    orderPageSummary() {
      const list = this.orders || []
      let settleableCount = 0
      let estimatedCount = 0
      let totalMinutes = 0
      list.forEach(r => {
        if (r.status === 'settleable') settleableCount += 1
        else estimatedCount += 1
        totalMinutes += Number(r.labor_minutes || 0)
      })
      return { settleableCount, estimatedCount, totalMinutes }
    },
  },
  watch: {
    tab(v) {
      if (v === 'wos' && !this.woLoaded) this.loadWos()
      if (v === 'production' && (!this.prodData || this.prodData.month !== this.month)) this.loadProduction()
      if (v === 'month' && (!this.monthData || this.monthData.month !== this.month)) this.loadMonth()
    },
  },
  created() {
    const q = this.$route.query || {}
    if (q.wo) {
      this.tab = 'batch'
      this.batchNo = String(q.wo)
      this.loadBatch()
    }
    this.loadMonth()
    this.loadOrders()
  },
  methods: {
    reload() {
      if (this.tab === 'month') return this.loadMonth()
      if (this.tab === 'wos') return this.loadWos()
      if (this.tab === 'production') return this.loadProduction()
      if (this.tab === 'batch' && this.batchNo) return this.loadBatch()
      this.loadOrders()
    },
    sourceTagType(label) {
      if (!label) return 'info'
      if (label.includes('订单')) return 'success'
      if (label.includes('备货')) return 'warning'
      if (label.includes('开料')) return 'danger'
      return 'info'
    },
    async loadMonth() {
      if (!this.month) return
      this.monthLoading = true
      try {
        const r = await getPerformanceMonth({ month: this.month })
        this.monthData = r.data.data
        const employees = (this.monthData && this.monthData.employees) || []
        this.monthExpandedKeys = employees.length <= 5 ? employees.map(e => e.employee_legacy_id) : []
      } catch (e) {
        this.$message.error(e.userMessage || '月度结算加载失败')
      } finally {
        this.monthLoading = false
      }
    },
    resetMonthFilter() {
      this.month = currentMonth()
      this.monthKeyword = ''
      this.loadMonth()
    },
    isMonthRowExpanded(row) {
      return this.monthExpandedKeys.includes(row.employee_legacy_id)
    },
    toggleMonthRow(row) {
      const id = row.employee_legacy_id
      const idx = this.monthExpandedKeys.indexOf(id)
      if (idx >= 0) this.monthExpandedKeys.splice(idx, 1)
      else this.monthExpandedKeys.push(id)
    },
    toggleExpandAllMonth() {
      if (this.monthAllExpanded) {
        this.monthExpandedKeys = []
      } else {
        this.monthExpandedKeys = this.filteredMonthEmployees.map(e => e.employee_legacy_id)
      }
    },
    async loadProduction() {
      if (!this.month) return
      this.prodLoading = true
      try {
        const r = await getPerformanceProduction({ month: this.month, kind: this.prodKind || undefined })
        this.prodData = r.data.data
        const employees = (this.prodData && this.prodData.employees) || []
        this.prodExpandedKeys = employees.length <= 5 ? employees.map(e => e.employee_legacy_id) : []
      } catch (e) {
        this.$message.error(e.userMessage || '生产明细加载失败')
      } finally {
        this.prodLoading = false
      }
    },
    resetProdFilter() {
      this.month = currentMonth()
      this.prodKind = ''
      this.prodKeyword = ''
      this.loadProduction()
    },
    isProdRowExpanded(row) {
      return this.prodExpandedKeys.includes(row.employee_legacy_id)
    },
    toggleProdRow(row) {
      const id = row.employee_legacy_id
      const idx = this.prodExpandedKeys.indexOf(id)
      if (idx >= 0) this.prodExpandedKeys.splice(idx, 1)
      else this.prodExpandedKeys.push(id)
    },
    toggleExpandAllProd() {
      if (this.prodAllExpanded) {
        this.prodExpandedKeys = []
      } else {
        this.prodExpandedKeys = this.prodPeople.map(e => e.employee_legacy_id)
      }
    },
    qty(v) {
      const n = Number(v || 0)
      return Number.isInteger(n) ? n : Number(n.toFixed(2))
    },
    searchWos() {
      this.woPage = 1
      this.loadWos()
    },
    toggleOnlyUnsettled(val) {
      this.onlyUnsettled = val
      this.searchWos()
    },
    onWoSizeChange(size) {
      this.woPerPage = size
      this.woPage = 1
      this.loadWos()
    },
    resetWosFilter() {
      this.woKeyword = ''
      this.onlyUnsettled = true
      this.woPage = 1
      this.woPerPage = 10
      this.loadWos()
    },
    async loadWos() {
      this.woLoading = true
      this.woLoaded = true
      try {
        const r = await listPerformanceWorkOrders({
          keyword: this.woKeyword,
          only_unsettled: this.onlyUnsettled ? 1 : 0,
          page: this.woPage,
          per_page: this.woPerPage,
        })
        this.wos = r.data.data || []
        this.woTotal = r.data.total || 0
        this.woSummary = r.data.summary || null
      } catch (e) {
        this.$message.error(e.userMessage || '加载失败')
      } finally {
        this.woLoading = false
      }
    },
    showBatch(no) {
      this.batchNo = no
      this.tab = 'batch'
      this.loadBatch()
    },
    clearBatch() {
      this.batchNo = ''
      this.batch = null
    },
    statusType(label) {
      return label === '已全部出库' || label === '已出库' || label === '已发运'
        ? 'success'
        : label === '部分出库'
          ? 'warning'
          : 'info'
    },
    search() {
      this.page = 1
      this.loadOrders()
    },
    onOrderSizeChange(size) {
      this.orderPerPage = size
      this.page = 1
      this.loadOrders()
    },
    resetOrderFilter() {
      this.keyword = ''
      this.orderStatusFilter = ''
      this.page = 1
      this.orderPerPage = 10
      this.loadOrders()
    },
    async loadOrders() {
      this.loading = true
      try {
        const r = await listPerformanceOrders({ keyword: this.keyword, page: this.page, per_page: this.orderPerPage })
        this.orders = r.data.data || []
        this.total = r.data.total || 0
      } catch (e) {
        this.$message.error(e.userMessage || '统计加载失败')
      } finally {
        this.loading = false
      }
    },
    async openOrder(row) {
      this.drawer = true
      this.detail = null
      this.detailLoading = true
      try {
        const r = await getPerformanceOrder(row.id)
        this.detail = r.data.data
      } catch (e) {
        this.$message.error(e.userMessage || '明细加载失败')
      } finally {
        this.detailLoading = false
      }
    },
    async loadBatch() {
      if (!this.batchNo || !this.batchNo.trim()) {
        this.$message.warning('请输入工单号或集中开料记录号')
        return
      }
      this.batchLoading = true
      try {
        const r = await getPerformanceBatch({ work_order_no: this.batchNo.trim() })
        this.batch = r.data.data
      } catch (e) {
        this.batch = null
        this.$message.error(e.userMessage || '没有找到这张工单')
      } finally {
        this.batchLoading = false
      }
    },
    flatten(line) {
      const rows = []
      const walk = (node, level) => {
        const label = node.kind_label && !level ? node.kind_label + ' · ' + (node.source_label || '库存') : (node.source_label || '库存')
        rows.push(Object.assign({}, node, { kind: 'wo', level, source_label: label }))
        if (node.note) rows.push({ kind: 'note', level, text: node.note })
        ;(node.operations || []).forEach(op => rows.push(Object.assign({}, op, { kind: 'op', level })))
        ;(node.notes || []).forEach(text => rows.push({ kind: 'note', level, text }))
        ;(node.children || []).forEach(c => walk(c, level + 1))
      }
      ;(line.sources || []).forEach(s => walk(s, 0))
      if ((line.packing || []).length) {
        rows.push({ kind: 'wo', level: 0, source_label: '发货包装', work_order_no: '', batch_no: '包装作业', item_name: '' })
        line.packing.forEach(op => rows.push(Object.assign({}, op, { kind: 'op', level: 0 })))
      }
      return rows
    },
    rowClass({ row }) {
      return row.kind === 'wo' ? 'wo-row' : (row.kind === 'note' ? 'note-row' : '')
    },
    money(v) {
      return v === null || v === undefined ? '—' : Number(v).toFixed(2)
    },
    moneySymbol(v) {
      return v === null || v === undefined ? '—' : '¥' + Number(v).toFixed(2)
    },
    hours(m) {
      return (Number(m || 0) / 60).toFixed(2)
    },
    pct(r, d = 1) {
      return (Number(r || 0) * 100).toFixed(d) + '%'
    },
  },
}
</script>

<style scoped src="./production-master.css"></style>
<style scoped>
/* ==================== 主数据中心统一页头 ==================== */
.perf-stats-page .page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 18px;
  margin-bottom: 12px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
}
.perf-stats-page .head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}
.perf-stats-page .head-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  background: rgba(0, 139, 75, 0.1);
  color: #008B4B;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}
.perf-stats-page .title-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.perf-stats-page .page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.3;
}
.perf-stats-page .head-tag {
  font-weight: 600;
  border-radius: 6px;
}
.perf-stats-page .head-tag.total-tag {
  color: #008B4B;
  border-color: rgba(0, 139, 75, 0.3);
  background: rgba(0, 139, 75, 0.06);
}
.perf-stats-page .head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
.btn-theme-create {
  background: #008B4B !important;
  border-color: #008B4B !important;
  color: #fff !important;
}
.btn-theme-create:hover,
.btn-theme-create:focus {
  background: #00753f !important;
  border-color: #00753f !important;
  color: #fff !important;
}

/* ==================== 主卡片与品牌绿页签 ==================== */
.perf-main-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 12px 16px 16px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
}
.perf-tabs ::v-deep .el-tabs__header {
  margin-bottom: 14px;
}
.perf-tabs ::v-deep .el-tabs__item {
  height: 42px;
  line-height: 42px;
  font-size: 14px;
  font-weight: 600;
  color: #475569;
}
.perf-tabs ::v-deep .el-tabs__item.is-active {
  color: #008B4B;
}
.perf-tabs ::v-deep .el-tabs__active-bar {
  background-color: #008B4B;
  height: 3px;
  border-radius: 2px;
}
.tab-label-wrap {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.tab-count-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 18px;
  padding: 0 6px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  background: rgba(0, 139, 75, 0.1);
  color: #008B4B;
}

/* ==================== 工具栏 ==================== */
.perf-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding: 10px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 12px;
}
.toolbar-left,
.toolbar-right {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.filter-label {
  font-size: 13px;
  font-weight: 600;
  color: #334155;
}
.perf-toolbar ::v-deep .el-radio-button__orig-radio:checked + .el-radio-button__inner {
  background-color: #008B4B;
  border-color: #008B4B;
  box-shadow: -1px 0 0 0 #008B4B;
}

/* ==================== 月度结转公式总览条 ==================== */
.formula-overview-grid {
  display: flex;
  align-items: stretch;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}
.formula-card {
  flex: 1 1 140px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 12px;
  min-width: 135px;
}
.formula-card.is-ok {
  background: #f0fdf4;
  border-color: #bbf7d0;
}
.formula-card.is-ok .formula-val {
  color: #15803d;
}
.formula-card.is-danger {
  background: #fef2f2;
  border-color: #fecaca;
}
.formula-card.is-danger .formula-val {
  color: #b91c1c;
}
.formula-card.is-warn {
  background: #fffbeb;
  border-color: #fde68a;
}
.formula-card.is-warn .formula-val {
  color: #b45309;
}
.formula-card.is-money {
  background: linear-gradient(135deg, rgba(0, 139, 75, 0.08), rgba(0, 139, 75, 0.03));
  border-color: rgba(0, 139, 75, 0.35);
}
.formula-card.is-money .formula-val {
  color: #008B4B;
  font-size: 18px;
}
.formula-label {
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
}
.formula-val {
  font-size: 17px;
  font-weight: 700;
  color: #0f172a;
  margin: 4px 0 2px;
}
.formula-val small {
  font-size: 12px;
  font-weight: 500;
  color: #64748b;
}
.formula-sub {
  font-size: 11px;
  color: #94a3b8;
}
.formula-op {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  font-weight: 700;
  color: #64748b;
  padding: 0 2px;
}

/* ==================== 顶部 4 宫格 KPI 卡片 ==================== */
.perf-kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}
.perf-kpi-card {
  padding: 12px 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.perf-kpi-card:hover {
  border-color: #cbd5e1;
  transform: translateY(-1px);
}
.perf-kpi-card.is-active-filter {
  border-color: #008B4B;
  background: rgba(0, 139, 75, 0.04);
  box-shadow: 0 0 0 1px rgba(0, 139, 75, 0.25);
}
.perf-kpi-card.is-green .kpi-value {
  color: #008B4B;
}
.perf-kpi-card.is-amber .kpi-value {
  color: #d97706;
}
.perf-kpi-card.is-danger .kpi-value {
  color: #dc2626;
}
.kpi-title {
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
}
.kpi-value {
  font-size: 19px;
  font-weight: 700;
  color: #0f172a;
  margin: 4px 0 2px;
}
.kpi-value small {
  font-size: 12px;
  font-weight: 500;
  color: #64748b;
}
.kpi-desc {
  font-size: 11px;
  color: #94a3b8;
}

/* ==================== 表格与展开子面板 ==================== */
.perf-table ::v-deep th {
  background: #f8fafc !important;
  color: #334155;
  font-weight: 600;
}
.clickable ::v-deep .el-table__row {
  cursor: pointer;
}
.emp-cell {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.emp-avatar {
  width: 24px;
  height: 24px;
  border-radius: 6px;
  background: rgba(0, 139, 75, 0.12);
  color: #008B4B;
  font-size: 12px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.emp-name {
  font-weight: 600;
  color: #0f172a;
}
.emp-wo-tag {
  margin-left: 2px;
}
.sub-detail-panel {
  padding: 10px 14px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin: 4px 8px;
}
.sub-detail-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.sub-detail-title {
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.sub-detail-title i {
  color: #008B4B;
}
.sub-detail-meta {
  font-size: 12px;
  color: #64748b;
}
.wo-link {
  color: #008B4B;
  font-family: Consolas, Monaco, monospace;
  font-weight: 700;
  text-decoration: none;
}
.wo-link:hover {
  text-decoration: underline;
}
.mono-bold {
  font-family: Consolas, Monaco, monospace;
  font-weight: 700;
  color: #0f172a;
}
.action-btn-green {
  color: #008B4B !important;
  font-weight: 600;
}
.action-btn-green:hover {
  color: #006b3a !important;
}
.wo-cell-main {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.item-primary {
  font-weight: 600;
  color: #1e293b;
}
.item-sub {
  font-size: 12px;
}
.dest-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.dest-pill {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
  background: #f1f5f9;
  color: #334155;
}
.dest-pill.is-ok {
  background: #dcfce7;
  color: #15803d;
}
.dest-pill.is-warn {
  background: #fef3c7;
  color: #b45309;
}
.dest-pill.is-danger {
  background: #fee2e2;
  color: #b91c1c;
}
.dest-pill.is-muted {
  background: #f8fafc;
  color: #64748b;
}

/* ==================== 批次去向页签 ==================== */
.quick-batch-bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}
.quick-batch-label {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
}
.quick-batch-tag {
  cursor: pointer;
  transition: all 0.15s;
}
.quick-batch-tag:hover {
  border-color: #008B4B;
  color: #008B4B;
}
.batch-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}
.batch-overview-cell {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 14px;
}
.batch-overview-cell.is-green {
  background: #f0fdf4;
  border-color: #bbf7d0;
}
.cell-label {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
}
.cell-main {
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
  margin: 4px 0 2px;
}
.cell-sub {
  font-size: 12px;
  color: #64748b;
}

/* ==================== 退货记录与颜色状态 ==================== */
.return-box {
  margin-bottom: 14px;
  padding: 12px 14px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 8px;
}
.sec-title {
  font-size: 13px;
  font-weight: 700;
  color: #92400e;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.ok-text {
  color: #15803d;
}
.warn-text {
  color: #d97706;
}
.bad-text {
  color: #dc2626;
}
.muted {
  color: #64748b;
}
.pager {
  margin-top: 14px;
  display: flex;
  justify-content: flex-end;
}

/* ==================== 订单提成与工时明细弹窗 ==================== */
.detail-dialog-body {
  max-height: 74vh;
  overflow-y: auto;
  padding-right: 4px;
}
.dialog-kpi-bar {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}
.dialog-kpi-item {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 12px;
}
.dialog-kpi-item.is-green {
  background: #f0fdf4;
  border-color: #bbf7d0;
}
.d-kpi-label {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
}
.d-kpi-val {
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
  margin: 4px 0 2px;
}
.d-kpi-sub {
  font-size: 11px;
  color: #94a3b8;
}
.issue-alert {
  margin-bottom: 8px;
}
.dialog-sec-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 12px 0 8px;
}
.sec-bar {
  width: 4px;
  height: 15px;
  border-radius: 2px;
  background: #008B4B;
}
.sec-txt {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}
.line-box {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 12px;
  background: #fcfcfd;
}
.line-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding-bottom: 8px;
  margin-bottom: 10px;
  border-bottom: 1px dashed #e2e8f0;
  font-size: 13px;
}
.line-head-left,
.line-head-right {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}
.line-badge {
  padding: 2px 8px;
  border-radius: 4px;
  background: rgba(0, 139, 75, 0.1);
  color: #008B4B;
  font-size: 12px;
  font-weight: 700;
}
.tag {
  margin-left: 6px;
}
.emp {
  display: inline-block;
  margin-right: 12px;
}
.note {
  color: #64748b;
  font-size: 12px;
}
.perf-table ::v-deep .wo-row {
  background: #f8fafc;
  font-weight: 600;
}
.perf-table ::v-deep .note-row {
  background: #fffbeb;
}
.mb-14 {
  margin-bottom: 14px;
}

@media (max-width: 1100px) {
  .perf-kpi-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .batch-overview-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .dialog-kpi-bar {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
